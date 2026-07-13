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
}
