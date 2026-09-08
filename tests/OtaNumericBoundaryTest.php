<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OtaNumericBoundaryTest extends TestCase
{
    public function testReadAndAggregationContractsRemainVisibleForNewAndLegacyDatasets(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([]);
        self::assertSame('ota_standard_read.20260904.v3', $dataset['read_contract_version']);
        $service = new OtaRevenueMetricService();
        $summary = $service->summarizeDataset($dataset);
        self::assertSame('ota_scoped_aggregation.20260904.v3', $summary['aggregation_contract_version']);
        self::assertSame($dataset['read_contract_version'], $summary['source_read_contract_version']);
        self::assertSame('legacy_unspecified', $service->summarizeDataset([])['source_read_contract_version']);
    }

    public function testExplicitPercentSuffixCannotMultiplySmallPercentagesByOneHundred(): void
    {
        $service = new OtaStandardEtlService();
        foreach (['nullablePercent', 'supplementalPercent'] as $methodName) {
            $method = new ReflectionMethod($service, $methodName);
            foreach (['0.5%' => 0.5, '1%' => 1.0, '0%' => 0.0, '12.5%' => 12.5] as $raw => $expected) {
                self::assertSame($expected, $method->invoke($service, [], ['rate' => $raw], ['rate']), $methodName . ':' . $raw);
            }
        }
    }

    public function testLegacyFractionInputRemainsCompatibleWithoutAnExplicitPercentUnit(): void
    {
        $service = new OtaStandardEtlService();
        $method = new ReflectionMethod($service, 'nullablePercent');
        self::assertSame(50.0, $method->invoke($service, [], ['rate' => 0.5], ['rate']));
    }

    public function testNumbersRejectInvalidGroupingPercentUnitsAndNonFiniteValues(): void
    {
        $service = new OtaStandardEtlService();
        $method = new ReflectionMethod($service, 'nullableNumber');
        foreach (['1,2', '12%', '1e9999', INF, NAN, '-'] as $value) {
            self::assertNull($method->invoke($service, [], ['amount' => $value], ['amount']));
        }
        self::assertSame(1234.56, $method->invoke($service, [], ['amount' => '1,234.56'], ['amount']));
        self::assertSame(0.0, $method->invoke($service, [], ['amount' => 0], ['amount']));
    }

    public function testNonFiniteStandardFactsCannotBecomeRevenueOrScores(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [['platform_key' => 'ctrip', 'revenue' => INF, 'room_revenue' => INF, 'room_nights' => 2]],
            'fact_ota_comment' => [['comment_score' => NAN]],
        ]);
        self::assertNull($metrics['totals']['revenue']);
        self::assertNull($metrics['totals']['adr']);
        self::assertNull($metrics['totals']['avg_comment_score']);
    }
}
