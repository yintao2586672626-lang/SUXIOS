<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

final class RevenueAiActualSourceScopeTest extends TestCase
{
    public function testAbsentChannelObjectsCannotClaimActualSourceFacts(): void
    {
        $overview = $this->overview([]);
        self::assertSame([], $overview['actual_source_channels']);
        self::assertSame(['ctrip', 'meituan'], $overview['source_channels']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
    }

    public function testSparseCtripFactsDoNotInventMeituanFacts(): void
    {
        $overview = $this->overview(['ctrip' => $this->dataset('ctrip')]);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
        self::assertSame(['ctrip', 'meituan'], $overview['source_channels']);
        self::assertEquals(100, $overview['metrics']['ota_room_revenue']['value']);
    }

    public function testExplicitEmptyObjectsRemainMissingWithoutSyntheticZeroMetrics(): void
    {
        $overview = $this->overview(['ctrip' => ['status' => 'empty'], 'meituan' => ['status' => 'empty']]);
        self::assertSame([], $overview['actual_source_channels']);
        self::assertSame(['ctrip', 'meituan'], $overview['source_channels']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
    }

    public function testBothActualChannelsRemainVisible(): void
    {
        $overview = $this->overview(['ctrip' => $this->dataset('ctrip'), 'meituan' => $this->dataset('meituan')]);
        self::assertSame(['ctrip', 'meituan'], $overview['actual_source_channels']);
        self::assertEquals(200, $overview['metrics']['ota_room_revenue']['value']);
    }

    public function testSparseMeituanFactsDoNotInventCtripFacts(): void
    {
        $overview = $this->overview(['meituan' => $this->dataset('meituan')]);
        self::assertSame(['meituan'], $overview['actual_source_channels']);
        self::assertSame(['ctrip', 'meituan'], $overview['source_channels']);
        self::assertEquals(100, $overview['metrics']['ota_room_revenue']['value']);
    }

    public function testFailedChannelObjectWithoutFactsDoesNotClaimAnActualSource(): void
    {
        foreach (['failed', 'error'] as $status) {
            $overview = $this->overview(['meituan' => ['status' => $status]]);
            self::assertSame([], $overview['actual_source_channels']);
            self::assertSame(['ctrip', 'meituan'], $overview['source_channels']);
            self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        }
    }

    public function testUnverifiedMetadataWithoutFactsCannotCountAsAnActualChannel(): void
    {
        $dataset = $this->dataset('ctrip');
        $dataset['fact_ota_daily'] = [];
        $overview = $this->overview([], $dataset);
        self::assertSame([], $overview['actual_source_channels']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
    }

    public function testAggregateFactPlatformWorksWithoutOptionalDimensionOrChannelMap(): void
    {
        $dataset = $this->dataset('ctrip');
        unset($dataset['dim_platform']);
        $overview = $this->overview([], $dataset);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
        self::assertSame(['ctrip', 'meituan'], $overview['source_channels']);
        self::assertEquals(100, $overview['metrics']['ota_room_revenue']['value']);
    }

    public function testForeignDateHotelPlatformOrTraceDoesNotLeavePhantomChannels(): void
    {
        foreach ([['date_key', '2026-07-27'], ['hotel_key', 'system:64'], ['platform_key', 'meituan'],
            ['source_trace', ['date_key' => '2026-07-27']],
            ['source_trace', ['system_hotel_id' => 64]], ['source_trace', ['platform' => 'meituan']]] as [$key, $value]) {
            $dataset = $this->dataset('ctrip');
            $dataset['fact_ota_daily'][0][$key] = $value;
            $overview = $this->overview(['ctrip' => $dataset]);
            self::assertSame([], $overview['actual_source_channels']);
            self::assertSame(['ctrip', 'meituan'], $overview['source_channels']);
            self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        }
    }

    public function testChannelOutsideTheRequestCannotBecomeAnActualSource(): void
    {
        $overview = $this->overview(['meituan' => $this->dataset('meituan')], ['status' => 'empty'], ['ctrip']);
        self::assertSame([], $overview['actual_source_channels']);
        self::assertSame(['ctrip'], $overview['source_channels']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
    }

    public function testTrafficOnlyFactsAreActualButCannotBecomeRoomRevenue(): void
    {
        $dataset = ['status' => 'ready', 'fact_ota_traffic' => [[
            'date_key' => '2026-07-28', 'hotel_key' => 'system:80', 'platform_key' => 'ctrip',
            'metric_scope' => 'ota_channel', 'visitors' => 15,
        ]]];
        $overview = $this->overview([], $dataset);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertNull($overview['metrics']['ota_room_nights']['value']);
    }

    private function overview(array $datasets, array $dataset = ['status' => 'empty'], array $channels = ['ctrip', 'meituan']): array
    {
        return (new RevenueAiOverviewService())->buildOverviewFromDataset($dataset, $datasets, [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => $channels]);
    }

    private function dataset(string $platform): array
    {
        return ['status' => 'ready', 'dim_hotel' => [['hotel_key' => 'system:80']],
            'dim_platform' => [['platform_key' => $platform]], 'fact_ota_daily' => [[
                'date_key' => '2026-07-28', 'hotel_key' => 'system:80', 'platform_key' => $platform,
                'data_type' => 'business', 'metric_scope' => 'ota_channel',
                'calculation_basis' => 'ota_daily_standard_fact', 'room_revenue' => 100,
                'revenue' => 100, 'gross_revenue' => 100, 'net_revenue' => 100,
                'room_nights' => 1, 'available_room_nights' => 10, 'order_count' => 1,
            ]], 'data_quality' => ['input_rows' => 1, 'accepted_rows' => 1, 'rejected_rows' => []]];
    }
}
