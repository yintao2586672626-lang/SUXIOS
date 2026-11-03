<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;

final class OtaPercentUnitEvidenceTest extends TestCase
{
    private function row(string $platform, array $fields = []): array
    {
        return array_merge([
            'id' => 1, 'system_hotel_id' => 80, 'hotel_id' => 'synthetic-80',
            'source' => $platform, 'data_type' => 'traffic', 'data_date' => '2026-08-03',
            'raw_data' => [],
        ], $fields);
    }

    public function testUnlabelledSmallRatesCannotBecomeStrictReadbackPercentages(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            foreach ([0.5, 1] as $value) {
                $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
                    $this->row($platform, ['flow_rate' => $value]),
                ]);
                $fact = $dataset['fact_ota_traffic'][0];
                self::assertNull($fact['flow_rate'], $platform . ':' . $value);
                self::assertNull($fact['stored_flow_rate']);
                self::assertSame('source_unit_unverified', $fact['flow_rate_validation_status']);
                self::assertContains('flow_rate_unit_unverified', $fact['flow_rate_quality_flags']);
                self::assertSame($value, $fact['flow_rate_source_value']);
                $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
                self::assertNull($summary['traffic']['avg_flow_rate']);
                self::assertContains('traffic_flow_rate_unit_unverified', array_column($summary['data_gaps'], 'code'));
            }
        }
    }

    public function testExplicitPercentAndRealZeroRemainUsableWithoutGuessing(): void
    {
        foreach (['0.5%' => 0.5, '1%' => 1.0, '0%' => 0.0, '12.5%' => 12.5] as $raw => $expected) {
            $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
                $this->row('ctrip', ['flow_rate' => $raw]),
            ]);
            self::assertSame($expected, $dataset['fact_ota_traffic'][0]['flow_rate']);
        }
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row('meituan', ['flow_rate' => 0]),
        ]);
        self::assertSame(0.0, $dataset['fact_ota_traffic'][0]['flow_rate']);
    }

    public function testMatchingOriginalPercentUnitSurvivesTheNumericStorageColumn(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row('ctrip', ['flow_rate' => 0.5, 'raw_data' => ['flowRate' => '0.5%']]),
        ]);
        self::assertSame(0.5, $dataset['fact_ota_traffic'][0]['flow_rate']);
        self::assertNotContains('flow_rate_unit_unverified', $dataset['fact_ota_traffic'][0]['flow_rate_quality_flags']);
        $mismatch = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row('ctrip', ['flow_rate' => 0.5, 'raw_data' => ['flowRate' => '50%']]),
        ]);
        self::assertNull($mismatch['fact_ota_traffic'][0]['flow_rate']);
    }

    public function testAlignedCountsStillSupportAComputedRateWhileLegacyUnitIsUnknown(): void
    {
        foreach (['ctrip', 'meituan'] as $platform) {
            $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
                $this->row($platform, ['flow_rate' => 0.5, 'list_exposure' => 200, 'detail_exposure' => 1]),
            ]);
            $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            self::assertSame(0.5, $summary['traffic']['avg_flow_rate']);
            self::assertContains('flow_rate_unit_unverified', $dataset['fact_ota_traffic'][0]['flow_rate_quality_flags']);
        }
    }

    public function testRawMeituanSmallRateAlsoRequiresUnitEvidenceWithoutCounts(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $this->row('meituan', ['raw_data' => ['exposure_to_browse_rate' => 0.5]]),
        ]);
        self::assertNull($dataset['fact_ota_traffic'][0]['flow_rate']);
        self::assertSame('source_unit_unverified', $dataset['fact_ota_traffic'][0]['flow_rate_validation_status']);
    }
}
