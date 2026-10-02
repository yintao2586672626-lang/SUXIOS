<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueFactLayerService;
use PHPUnit\Framework\TestCase;

final class RevenueFactLayerPmsAdrAvailabilityTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/revenue-fact-layer/pms-adr-denominator-captures.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function layer(array $capture): array
    {
        $fixture = $this->fixture();
        return (new RevenueFactLayerService(
            hotelLoader: static fn(): array => $fixture['hotel'],
            pmsLoader: static fn(): array => $capture,
            otaLoader: static fn(): array => ['data_status' => 'missing', 'rows' => []],
            pricingGuardLoader: static fn(): array => [],
            pmsBindingLoader: static fn(): array => ['binding_status' => 'configured', 'selected_provider' => 'meituan_cloud_pms']
        ))->build(80, $fixture['business_date'], ['isolated_no_ota_database' => []]);
    }

    public function testZeroSoldRoomsDoNotTurnReportedAdrIntoACalculableRatio(): void
    {
        foreach (['empty_zero_adr', 'empty_reported_adr'] as $key) {
            $capture = $this->fixture()['captures'][$key];
            $original = $capture;
            $layer = $this->layer($capture);
            $pms = $layer['sources']['meituan_cloud_pms'];
            self::assertSame('readback_verified', $pms['data_status']);
            self::assertNull($pms['facts']['adr'], $key);
            self::assertNull($layer['facts']['whole_hotel_accommodation']['adr']);
            self::assertSame('not_calculable', $pms['fact_statuses']['adr']['status']);
            self::assertSame('pms_sold_room_nights_denominator_zero', $pms['fact_statuses']['adr']['reason']);
            self::assertSame('not_calculable', $layer['derived_metrics']['whole_hotel_adr']['status']);
            self::assertNull($layer['derived_metrics']['whole_hotel_adr']['value']);
            self::assertSame('pms_sold_room_nights_denominator_zero', $layer['derived_metrics']['whole_hotel_adr']['reason']);
            self::assertSame(0.0, $pms['facts']['room_revenue']);
            self::assertSame(0, $pms['facts']['sold_room_nights']);
            self::assertSame(12, $pms['facts']['sellable_room_nights']);
            self::assertSame(0.0, $pms['facts']['revpar']);
            self::assertSame('ready', $layer['derived_metrics']['whole_hotel_revpar']['status']);
            self::assertSame($original, $capture, 'captured reported ADR remains unchanged');
        }
    }

    public function testOccupiedRoomsWithZeroRevenueKeepAGenuineZeroAdr(): void
    {
        $layer = $this->layer($this->fixture()['captures']['occupied_zero_revenue']);
        self::assertSame(0.0, $layer['facts']['whole_hotel_accommodation']['adr']);
        self::assertSame('readback_verified', $layer['sources']['meituan_cloud_pms']['fact_statuses']['adr']['status']);
        self::assertSame(0.0, $layer['derived_metrics']['whole_hotel_adr']['value']);
        self::assertSame('ready', $layer['derived_metrics']['whole_hotel_adr']['status']);
    }

    public function testNormalReadbackRestoresAdrWithoutUsingADifferentScope(): void
    {
        $capture = $this->fixture()['captures']['occupied_normal'];
        foreach (['hotel_id' => 81, 'tenant_id' => 9, 'business_date' => '2026-07-27', 'readback_status' => 'read_failed'] as $key => $value) {
            $bad = $capture;
            $bad[$key] = $value;
            $layer = $this->layer($bad);
            self::assertNull($layer['derived_metrics']['whole_hotel_adr']['value']);
            self::assertNotSame('ready', $layer['derived_metrics']['whole_hotel_adr']['status']);
        }
        $layer = $this->layer($capture);
        self::assertSame(100.0, $layer['derived_metrics']['whole_hotel_adr']['value']);
        self::assertSame('ready', $layer['derived_metrics']['whole_hotel_adr']['status']);
    }

    public function testMissingAdrDoesNotBecomeZeroOrUseAReportedPriorValue(): void
    {
        $capture = $this->fixture()['captures']['occupied_normal'];
        unset($capture['summary']['adr']);
        $layer = $this->layer($capture);
        self::assertNull($layer['facts']['whole_hotel_accommodation']['adr']);
        self::assertSame('not_calculable', $layer['derived_metrics']['whole_hotel_adr']['status']);
        self::assertSame('meituan_cloud_pms_not_readback_verified', $layer['derived_metrics']['whole_hotel_adr']['reason']);
    }
}
