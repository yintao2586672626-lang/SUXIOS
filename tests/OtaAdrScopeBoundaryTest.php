<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;

final class OtaAdrScopeBoundaryTest extends TestCase
{
    private function fact(string $date, ?float $revenue, ?float $nights, array $extra = []): array
    {
        return array_merge(['date_key' => $date, 'hotel_key' => 'system:80', 'platform_key' => 'meituan',
            'room_revenue' => $revenue, 'room_nights' => $nights], $extra);
    }

    public function testAnotherDayCannotSupplyAMissingAdrNumeratorOrDenominator(): void
    {
        $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_daily' => [
            $this->fact('2026-08-01', 1000, 5),
            $this->fact('2026-08-02', null, 95),
        ]]);
        self::assertNull($result['totals']['adr']);
        self::assertNull($result['by_platform'][0]['adr']);
        self::assertContains('adr_scope_incomplete', array_column($result['data_gaps'], 'code'));
    }

    public function testSameDayExplicitRoomNightAdjustmentKeepsItsExistingMeaning(): void
    {
        $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_daily' => [
            $this->fact('2026-08-01', 1000, 3),
            $this->fact('2026-08-01', null, 2, ['dimension' => 'room_nights_adjustment']),
        ]]);
        self::assertSame(200.0, $result['totals']['adr']);
    }

    public function testDifferentHotelsAndPlatformsCannotFillEachOthersMissingRoomFacts(): void
    {
        foreach ([['hotel_key' => 'system:81'], ['platform_key' => 'ctrip']] as $scope) {
            $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_daily' => [
                $this->fact('2026-08-01', 1000, null),
                $this->fact('2026-08-01', null, 5, $scope),
            ]]);
            self::assertNull($result['totals']['adr']);
        }
    }

    public function testEmptyBusinessDayFromEtlRemainsAnAdrCoverageGap(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
                ['id' => 1, 'system_hotel_id' => 80, 'hotel_id' => 'fixture-hotel', 'source' => $platform,
                    'data_type' => 'business', 'data_date' => '2026-08-01', 'room_revenue' => 1000, 'quantity' => 5],
                ['id' => 2, 'system_hotel_id' => 80, 'hotel_id' => 'fixture-hotel', 'source' => $platform,
                    'data_type' => 'business', 'data_date' => '2026-08-02', 'room_revenue' => null, 'quantity' => null],
            ]);
            self::assertCount(2, $dataset['fact_ota_daily']);
            $result = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            self::assertNull($result['totals']['adr']);
            self::assertSame(['total' => 2, 'complete' => 1], $result['totals']['adr_scope_coverage']);
            self::assertContains('adr_scope_incomplete', array_column($result['data_gaps'], 'code'));
        }
    }

    public function testExplicitNonRevenueProjectionDoesNotCreateAnAdrDay(): void
    {
        foreach (['ctrip_capacity_daily', 'ctrip_non_revenue_business_fact', 'ctrip_market_overview_booking_daily'] as $semanticScope) {
            $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_daily' => [
                $this->fact('2026-08-01', 1000, 5, ['data_type' => 'business']),
                $this->fact('2026-08-02', null, null, ['data_type' => 'business', 'metric_semantic_scope' => $semanticScope]),
            ]]);
            self::assertSame(200.0, $result['totals']['adr']);
            self::assertSame(['total' => 1, 'complete' => 1], $result['totals']['adr_scope_coverage']);
        }
    }
}
