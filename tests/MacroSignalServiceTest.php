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

    public function testTrendSeriesDoesNotReplaceExplicitZeroDailyRevenueWithOtaRevenue(): void
    {
        $series = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendSeries', [
            [[
                'hotel_id' => 121,
                'report_date' => '2026-05-01',
                'status' => 2,
                'revenue' => 0,
                'room_count' => 10,
                'report_data' => json_encode(['day_total_revenue' => 0, 'day_total_rooms' => 0]),
            ]],
            [[
                'system_hotel_id' => 121,
                'data_date' => '2026-05-01',
                'hotel_name' => '我的酒店',
                'amount' => 800,
                'quantity' => 4,
                'book_order_num' => 3,
                'dimension' => '',
                'raw_data' => json_encode(['hotelName' => '我的酒店']),
            ]],
            [], '2026-05-01', '2026-05-01',
        ]);

        self::assertSame(0.0, $series['rows'][0]['revenue']);
        self::assertSame(0.0, $series['rows'][0]['room_nights']);
        self::assertSame(0.0, $series['rows'][0]['occupancy']);
        self::assertSame(0.0, $series['rows'][0]['revpar']);
        self::assertTrue($series['rows'][0]['has_sample']);
    }

    public function testDraftDailyReportDoesNotSuppressRecordedOtaSample(): void
    {
        $series = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendSeries', [
            [[
                'hotel_id' => 121, 'report_date' => '2026-05-01', 'status' => 1,
                'revenue' => 0, 'report_data' => json_encode(['day_total_revenue' => 0, 'day_total_rooms' => 0]),
            ]],
            [[
                'system_hotel_id' => 121, 'data_date' => '2026-05-01',
                'hotel_name' => '我的酒店', 'amount' => 800, 'quantity' => 4,
                'book_order_num' => 3, 'raw_data' => json_encode(['hotelName' => '我的酒店']),
            ]],
            [], '2026-05-01', '2026-05-01',
        ]);

        self::assertSame(800.0, $series['rows'][0]['revenue']);
        self::assertSame(4.0, $series['rows'][0]['room_nights']);
    }

    public function testDraftDailyReportDoesNotAlterPriceSignalAdrOrOccupancy(): void
    {
        $service = new MacroSignalService();
        $daily = [
            ['status' => 1, 'report_data' => json_encode(['day_adr' => 900, 'day_occ_rate' => 99])],
            ['status' => 2, 'report_data' => json_encode(['day_adr' => 100, 'day_occ_rate' => 80])],
        ];

        self::assertSame(100.0, $this->invokeNonPublic($service, 'avgAdr', [[], $daily]));
        self::assertSame(80.0, $this->invokeNonPublic($service, 'avgOccupancy', [$daily]));
        self::assertFalse($this->invokeNonPublic($service, 'isSubmittedOrLegacyDailyRow', [['status' => 1]]));
        self::assertTrue($this->invokeNonPublic($service, 'isSubmittedOrLegacyDailyRow', [['status' => 2]]));
        self::assertTrue($this->invokeNonPublic($service, 'isSubmittedOrLegacyDailyRow', [[]]));
    }

    public function testTrendSeriesHonorsExplicitZeroDailyTotalsDespitePositiveChannelBreakdown(): void
    {
        $series = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendSeries', [
            [[
                'hotel_id' => 121, 'report_date' => '2026-05-01', 'revenue' => 0,
                'report_data' => json_encode([
                    'day_total_revenue' => 0, 'day_total_rooms' => 0,
                    'tc_revenue' => 800, 'tc_rooms' => 4,
                ]),
            ]],
            [], [], '2026-05-01', '2026-05-01',
        ]);

        self::assertSame(0.0, $series['rows'][0]['revenue']);
        self::assertSame(0.0, $series['rows'][0]['room_nights']);
    }

    public function testEditedDailyRevenueTotalOverridesStaleLegacySummaryColumn(): void
    {
        $service = new MacroSignalService();
        self::assertSame(0.0, $this->invokeNonPublic($service, 'dailyReportRevenue', [
            ['revenue' => 800], ['day_revenue' => 0, 'tc_revenue' => 800],
        ]));
        self::assertSame(100.0, $this->invokeNonPublic($service, 'dailyReportRevenue', [
            ['revenue' => 800], ['day_revenue' => 100, 'tc_revenue' => 800],
        ]));
        self::assertSame(800.0, $this->invokeNonPublic($service, 'dailyReportRevenue', [
            ['revenue' => 800], [],
        ]));

        $series = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [[
                'hotel_id' => 121, 'report_date' => '2026-05-01', 'status' => 2,
                'revenue' => 800, 'report_data' => json_encode(['day_revenue' => 0]),
            ]],
            [[
                'system_hotel_id' => 121, 'data_date' => '2026-05-01',
                'hotel_name' => '我的酒店', 'amount' => 900,
                'raw_data' => json_encode(['hotelName' => '我的酒店']),
            ]],
            [], '2026-05-01', '2026-05-01',
        ]);
        self::assertSame(0.0, $series['rows'][0]['revenue']);
    }

    public function testDailyRevenueFallbackRequiresCompleteRoomAndOtherIncome(): void
    {
        $service = new MacroSignalService();
        self::assertSame(120.0, $this->invokeNonPublic($service, 'dailyReportRevenue', [
            ['revenue' => null], ['room_revenue' => 100, 'other_revenue_total' => 20],
        ]));
        self::assertSame(20.0, $this->invokeNonPublic($service, 'dailyReportRevenue', [
            ['revenue' => null], ['room_revenue' => 0, 'other_revenue_total' => 20],
        ]));
        self::assertNull($this->invokeNonPublic($service, 'dailyReportRevenue', [
            ['revenue' => null], ['room_revenue' => 100],
        ]));

        $daily = static fn (array $data): array => [
            'hotel_id' => 121, 'report_date' => '2026-05-01', 'status' => 2,
            'revenue' => null, 'report_data' => json_encode($data),
        ];
        $online = [[
            'system_hotel_id' => 121, 'data_date' => '2026-05-01',
            'hotel_name' => '我的酒店', 'amount' => 800,
            'raw_data' => json_encode(['hotelName' => '我的酒店']),
        ]];
        $incomplete = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [$daily(['room_revenue' => 100])], $online, [], '2026-05-01', '2026-05-01',
        ]);
        $complete = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [$daily(['room_revenue' => 100, 'other_revenue_total' => 20])], $online, [], '2026-05-01', '2026-05-01',
        ]);
        self::assertSame(800.0, $incomplete['rows'][0]['revenue']);
        self::assertSame(120.0, $complete['rows'][0]['revenue']);
    }

    public function testDailyRoomNightsRequireCompleteOnlineAndOfflineEvidence(): void
    {
        $service = new MacroSignalService();
        self::assertSame(6.0, $this->invokeNonPublic($service, 'dailyReportRoomNights', [
            ['online_rooms' => 4, 'offline_rooms' => 2],
        ]));
        self::assertNull($this->invokeNonPublic($service, 'dailyReportRoomNights', [
            ['online_rooms' => 4],
        ]));
        self::assertSame(0.0, $this->invokeNonPublic($service, 'dailyReportRoomNights', [
            ['day_total_rooms' => 0, 'online_rooms' => 4, 'offline_rooms' => 2],
        ]));

        $daily = static fn (array $data): array => [
            'hotel_id' => 121, 'report_date' => '2026-05-01', 'status' => 2,
            'report_data' => json_encode($data),
        ];
        $online = [[
            'system_hotel_id' => 121, 'data_date' => '2026-05-01',
            'hotel_name' => '我的酒店', 'quantity' => 8,
            'raw_data' => json_encode(['hotelName' => '我的酒店']),
        ]];
        $partial = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [$daily(['online_rooms' => 4])], $online, [], '2026-05-01', '2026-05-01',
        ]);
        $complete = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [$daily(['online_rooms' => 4, 'offline_rooms' => 2])], $online, [], '2026-05-01', '2026-05-01',
        ]);
        self::assertSame(8.0, $partial['rows'][0]['room_nights']);
        self::assertSame(6.0, $complete['rows'][0]['room_nights']);
    }

    public function testTrendSeriesFallsBackToOtaOnlyWhenDailyRevenueIsMissing(): void
    {
        $series = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendSeries', [
            [[
                'hotel_id' => 121, 'report_date' => '2026-05-01',
                'revenue' => null, 'report_data' => '{}',
            ]],
            [[
                'system_hotel_id' => 121, 'data_date' => '2026-05-01',
                'hotel_name' => '我的酒店', 'amount' => 800, 'quantity' => 4,
                'book_order_num' => 3, 'dimension' => '',
                'raw_data' => json_encode(['hotelName' => '我的酒店']),
            ]],
            [], '2026-05-01', '2026-05-01',
        ]);

        self::assertSame(800.0, $series['rows'][0]['revenue']);
        self::assertSame(4.0, $series['rows'][0]['room_nights']);
    }

    public function testRevenueTrendCardDistinguishesRecordedZeroFromMissingSamples(): void
    {
        $service = new MacroSignalService();
        $card = $this->invokeNonPublic($service, 'buildRevenueTrendCard', [[
            ['revenue' => 0.0], ['revenue' => 0.0],
        ], '近2日']);

        self::assertSame('available', $card['status']);
        self::assertSame('¥0', $card['value']);
        self::assertStringContainsString('零收入', $card['note']);
        self::assertStringNotContainsString('数据不足', $card['impact']);
    }

    public function testRevenueTrendCardDoesNotCompareTwoOldSamplesAsCurrentGrowth(): void
    {
        $card = $this->invokeNonPublic(new MacroSignalService(), 'buildRevenueTrendCard', [[
            ['revenue' => 100.0], ['revenue' => 120.0],
            ['revenue' => null], ['revenue' => null],
        ], '近4日']);

        self::assertSame('available', $card['status']);
        self::assertNull($card['change_rate']);
        self::assertStringNotContainsString('上升', $card['direction']);
        self::assertStringContainsString('缺少可比样本', $card['note']);

        $currentOnly = $this->invokeNonPublic(new MacroSignalService(), 'buildRevenueTrendCard', [[
            ['revenue' => null], ['revenue' => null],
            ['revenue' => 100.0], ['revenue' => 120.0],
        ], '近4日']);
        self::assertNull($currentOnly['change_rate']);
        self::assertStringContainsString('缺少可比样本', $currentOnly['note']);
    }

    public function testRevenueTrendComparesObservedValuesWithinTheirDateSegments(): void
    {
        $service = new MacroSignalService();
        $series = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [
                ['hotel_id' => 121, 'report_date' => '2026-05-01', 'status' => 2, 'revenue' => 100, 'report_data' => '{}'],
                ['hotel_id' => 121, 'report_date' => '2026-05-04', 'status' => 2, 'revenue' => 1000, 'report_data' => '{}'],
                ['hotel_id' => 121, 'report_date' => '2026-05-05', 'status' => 2, 'revenue' => 10, 'report_data' => '{}'],
                ['hotel_id' => 121, 'report_date' => '2026-05-06', 'status' => 2, 'revenue' => 10, 'report_data' => '{}'],
            ],
            [], [], '2026-05-01', '2026-05-06',
        ]);

        self::assertNull($series['rows'][1]['revenue']);
        self::assertNull($series['rows'][2]['revenue']);
        $card = $this->invokeNonPublic($service, 'buildRevenueTrendCard', [$series['rows'], '近6日']);

        self::assertSame('上升', $card['direction']);
        self::assertSame(240.0, $card['change_rate']);
        self::assertStringContainsString('前段1/3日', $card['note']);
        self::assertStringContainsString('后段3/3日', $card['note']);
    }

    public function testDemandTrendCardDoesNotCompareOnlyEarlyOrdersAsCurrentGrowth(): void
    {
        $card = $this->invokeNonPublic(new MacroSignalService(), 'buildDemandTrendCard', [[
            ['orders' => 2.0], ['orders' => 4.0],
            ['orders' => 0.0], ['orders' => 0.0],
        ], [], '近4日']);

        self::assertSame('available', $card['status']);
        self::assertNull($card['change_rate']);
        self::assertSame('不可比', $card['direction']);
        self::assertStringContainsString('缺少可比样本', $card['note']);
        self::assertStringNotContainsString('平稳', $card['impact']);

        $currentOnly = $this->invokeNonPublic(new MacroSignalService(), 'buildDemandTrendCard', [[
            ['orders' => 0.0], ['orders' => 0.0],
            ['orders' => 2.0], ['orders' => 4.0],
        ], [], '近4日']);
        self::assertNull($currentOnly['change_rate']);
        self::assertSame('不可比', $currentOnly['direction']);
    }

    public function testDemandTrendComparesObservedOrdersWithinTheirDateSegments(): void
    {
        $service = new MacroSignalService();
        $onlineRows = [];
        foreach ([
            '2026-05-01' => 1,
            '2026-05-04' => 10,
            '2026-05-05' => 1,
            '2026-05-06' => 1,
        ] as $date => $orders) {
            $onlineRows[] = [
                'system_hotel_id' => 121,
                'data_date' => $date,
                'hotel_name' => '我的酒店',
                'book_order_num' => $orders,
                'amount' => 0,
                'quantity' => 0,
                'raw_data' => json_encode(['hotelName' => '我的酒店']),
            ];
        }
        $series = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [], $onlineRows, [], '2026-05-01', '2026-05-06',
        ]);

        self::assertSame([1.0, 0.0, 0.0, 10.0, 1.0, 1.0], array_column($series['rows'], 'orders'));
        $card = $this->invokeNonPublic($service, 'buildDemandTrendCard', [$series['rows'], [], '近6日']);

        self::assertSame('上升', $card['direction']);
        self::assertSame(300.0, $card['change_rate']);
        self::assertStringContainsString('前段1/3日', $card['note']);
        self::assertStringContainsString('后段3/3日', $card['note']);
    }

    public function testPriceTrendCardDoesNotCompareOnlyEarlyAdrAsCurrentGrowth(): void
    {
        $rows = [
            ['adr' => 100.0], ['adr' => 120.0],
            ['adr' => null], ['adr' => null],
        ];
        $service = new MacroSignalService();
        $card = $this->invokeNonPublic($service, 'buildPriceTrendCard', [$rows, 0.0, '近4日']);
        self::assertNull($card['change_rate']);
        self::assertSame('数据不足', $card['trend_direction']);
        self::assertStringContainsString('缺少可比样本', $card['note']);
        self::assertStringContainsString('暂不判断价格趋势', $card['impact']);

        $withCompetitor = $this->invokeNonPublic($service, 'buildPriceTrendCard', [$rows, 90.0, '近4日']);
        self::assertNull($withCompetitor['change_rate']);
        self::assertSame('数据不足', $withCompetitor['trend_direction']);
        self::assertStringContainsString('竞对均价', $withCompetitor['note']);
        self::assertStringContainsString('ADR记录日覆盖：前段2/2日、后段0/2日', $withCompetitor['note']);
    }

    public function testPriceTrendComparesObservedAdrWithinItsDateSegments(): void
    {
        $dailyRows = [];
        foreach ([
            '2026-05-01' => 100,
            '2026-05-04' => 1000,
            '2026-05-05' => 10,
            '2026-05-06' => 10,
        ] as $date => $adr) {
            $dailyRows[] = [
                'hotel_id' => 121,
                'report_date' => $date,
                'report_data' => json_encode(['day_adr' => $adr], JSON_UNESCAPED_UNICODE),
            ];
        }

        $service = new MacroSignalService();
        $series = $this->invokeNonPublic($service, 'buildTrendSeries', [
            $dailyRows, [], [], '2026-05-01', '2026-05-06',
        ]);
        self::assertSame([100.0, null, null, 1000.0, 10.0, 10.0], array_column($series['rows'], 'adr'));

        $card = $this->invokeNonPublic($service, 'buildPriceTrendCard', [$series['rows'], 0.0, '近6日']);
        self::assertSame('上升', $card['trend_direction']);
        self::assertSame(240.0, $card['change_rate']);
        self::assertStringContainsString('前段1/3日', $card['note']);
        self::assertStringContainsString('后段3/3日', $card['note']);
    }

    public function testChannelTrendCardDoesNotCompareSingleSidedConversionOrOrders(): void
    {
        $service = new MacroSignalService();
        $conversion = $this->invokeNonPublic($service, 'buildChannelTrendCard', [[
            ['channel_conversion' => 2.0, 'orders' => 1.0, 'exposure' => 100.0],
            ['channel_conversion' => 4.0, 'orders' => 2.0, 'exposure' => 100.0],
            ['channel_conversion' => null, 'orders' => 0.0, 'exposure' => 0.0],
            ['channel_conversion' => null, 'orders' => 0.0, 'exposure' => 0.0],
        ], '近4日']);
        self::assertNull($conversion['change_rate']);
        self::assertSame('不可比', $conversion['direction']);
        self::assertStringContainsString('缺少可比样本', $conversion['note']);

        $orders = $this->invokeNonPublic($service, 'buildChannelTrendCard', [[
            ['channel_conversion' => null, 'orders' => 2.0, 'exposure' => 0.0],
            ['channel_conversion' => null, 'orders' => 4.0, 'exposure' => 0.0],
            ['channel_conversion' => null, 'orders' => 0.0, 'exposure' => 0.0],
            ['channel_conversion' => null, 'orders' => 0.0, 'exposure' => 0.0],
        ], '近4日']);
        self::assertNull($orders['change_rate']);
        self::assertSame('不可比', $orders['direction']);
    }

    public function testChannelOrderOnlyTrendComparesOrdersWithinTheirDateSegments(): void
    {
        $onlineRows = [];
        foreach ([
            '2026-05-01' => 1,
            '2026-05-04' => 10,
            '2026-05-05' => 1,
            '2026-05-06' => 1,
        ] as $date => $orders) {
            $onlineRows[] = [
                'system_hotel_id' => 121,
                'data_date' => $date,
                'hotel_name' => '我的酒店',
                'source' => 'meituan',
                'book_order_num' => $orders,
                'amount' => 0,
                'quantity' => 0,
                'raw_data' => json_encode([
                    'hotelName' => '我的酒店',
                    'platform' => 'meituan',
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        $service = new MacroSignalService();
        $series = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [], $onlineRows, [], '2026-05-01', '2026-05-06',
        ]);
        self::assertSame([1.0, 0.0, 0.0, 10.0, 1.0, 1.0], array_column($series['rows'], 'orders'));
        self::assertSame([null, null, null, null, null, null], array_column($series['rows'], 'channel_conversion'));

        $card = $this->invokeNonPublic($service, 'buildChannelTrendCard', [$series['rows'], '近6日']);
        self::assertSame('上升', $card['direction']);
        self::assertSame(300.0, $card['change_rate']);
        self::assertStringContainsString('前段1/3日', $card['note']);
        self::assertStringContainsString('后段3/3日', $card['note']);
    }

    public function testChannelConversionTrendComparesObservedValuesWithinTheirDateSegments(): void
    {
        $onlineRows = [];
        foreach ([
            '2026-05-01' => 5,
            '2026-05-04' => 50,
            '2026-05-05' => 10,
            '2026-05-06' => 10,
        ] as $date => $conversionRate) {
            $onlineRows[] = [
                'system_hotel_id' => 121,
                'data_date' => $date,
                'hotel_name' => '我的酒店',
                'source' => 'meituan',
                'book_order_num' => 1,
                'amount' => 0,
                'quantity' => 0,
                'raw_data' => json_encode([
                    'hotelName' => '我的酒店',
                    'platform' => 'meituan',
                    'conversionRate' => $conversionRate,
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        $service = new MacroSignalService();
        $series = $this->invokeNonPublic($service, 'buildTrendSeries', [
            [], $onlineRows, [], '2026-05-01', '2026-05-06',
        ]);
        self::assertSame([5.0, null, null, 50.0, 10.0, 10.0], array_column($series['rows'], 'channel_conversion'));

        $card = $this->invokeNonPublic($service, 'buildChannelTrendCard', [$series['rows'], '近6日']);
        self::assertSame('上升', $card['direction']);
        self::assertSame(366.7, $card['change_rate']);
        self::assertSame('18.8%', $card['value']);
        self::assertStringContainsString('转化率记录日覆盖：前段1/3日、后段3/3日', $card['note']);
    }

    public function testTrendSeriesKeepsAnotherHotelsOtaSampleWhenOneHotelReportsZero(): void
    {
        $series = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendSeries', [
            [[
                'hotel_id' => 121, 'report_date' => '2026-05-01', 'revenue' => 0,
                'report_data' => json_encode(['day_total_revenue' => 0, 'day_total_rooms' => 0]),
            ]],
            [[
                'system_hotel_id' => 122, 'data_date' => '2026-05-01',
                'hotel_name' => '我的酒店', 'amount' => 800, 'quantity' => 4,
                'book_order_num' => 3, 'dimension' => '',
                'raw_data' => json_encode(['hotelName' => '我的酒店']),
            ]],
            [], '2026-05-01', '2026-05-01',
        ]);

        self::assertSame(800.0, $series['rows'][0]['revenue']);
        self::assertSame(4.0, $series['rows'][0]['room_nights']);
    }

    public function testTrendSeriesChoosesDailyOrOtaPerHotelWithoutDoubleCounting(): void
    {
        $online = static fn (int $hotelId, int $amount): array => [
            'system_hotel_id' => $hotelId, 'data_date' => '2026-05-01',
            'hotel_name' => '我的酒店', 'amount' => $amount, 'quantity' => 4,
            'book_order_num' => 3, 'dimension' => '',
            'raw_data' => json_encode(['hotelName' => '我的酒店']),
        ];
        $series = $this->invokeNonPublic(new MacroSignalService(), 'buildTrendSeries', [
            [[
                'hotel_id' => 121, 'report_date' => '2026-05-01', 'revenue' => 100,
                'report_data' => json_encode(['day_total_revenue' => 100, 'day_total_rooms' => 2]),
            ]],
            [$online(121, 900), $online(122, 800)],
            [], '2026-05-01', '2026-05-01',
        ]);

        self::assertSame(900.0, $series['rows'][0]['revenue']);
        self::assertSame(6.0, $series['rows'][0]['room_nights']);
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
        self::assertSame('5.5%', $channel['value']);
        self::assertSame('上升', $channel['direction']);
        self::assertSame(20.0, $channel['change_rate']);
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
