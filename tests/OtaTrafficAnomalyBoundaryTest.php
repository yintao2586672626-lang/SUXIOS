<?php
declare(strict_types=1);
namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;

final class OtaTrafficAnomalyBoundaryTest extends TestCase
{
    private function fact(array $values, string $date = '2026-08-01'): array
    {
        return array_merge(['hotel_key'=>'system:80','platform_key'=>'meituan',
            'date_key'=>$date,'compare_type'=>'self','source_trace'=>[]], $values);
    }

    public function testStandardizationRetainsAbnormalCountsWithoutVerifyingTheirRate(): void
    {
        foreach ([[100,200],[0,10],[-1,10],[100,-1]] as [$exposure,$browse]) {
            $service = new OtaStandardEtlService();
            $fact = (new \ReflectionMethod($service, 'trafficFact'))->invoke($service,
                ['list_exposure'=>$exposure,'detail_exposure'=>$browse], [],
                'system:80', 'meituan', '2026-08-01');
            self::assertSame($exposure, $fact['list_exposure']);
            self::assertSame($browse, $fact['detail_exposure']);
            self::assertNull($fact['flow_rate']);
            self::assertSame('counts_invalid', $fact['flow_rate_validation_status']);
            self::assertContains('flow_rate_counts_invalid', $fact['flow_rate_quality_flags']);
        }
    }

    public function testAbnormalDayCannotBeDilutedByNormalDaysOrBySingleRateFastPath(): void
    {
        $bad = $this->fact(['list_exposure'=>100,'detail_exposure'=>200,'flow_rate'=>200,
            'order_filling_num'=>10,'order_submit_num'=>20,'submit_rate'=>200]);
        $good = $this->fact(['list_exposure'=>1000,'detail_exposure'=>10,'flow_rate'=>1,
            'order_filling_num'=>100,'order_submit_num'=>1,'submit_rate'=>1], '2026-08-02');
        foreach ([[$bad],[$bad,$good]] as $rows) {
            $summary = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_traffic'=>$rows]);
            self::assertNull($summary['traffic']['avg_flow_rate']);
            self::assertNull($summary['traffic']['avg_submit_rate']);
            self::assertContains('traffic_flow_rate_counts_invalid', array_column($summary['data_gaps'],'code'));
            self::assertContains('traffic_submit_rate_counts_invalid', array_column($summary['data_gaps'],'code'));
        }
    }

    public function testConflictingPlatformCaliberCannotBecomeVerifiedByRecalculation(): void
    {
        $row = $this->fact(['list_exposure'=>100,'detail_exposure'=>10,'flow_rate'=>null,
            'flow_rate_validation_status'=>'caliber_uncertain',
            'flow_rate_quality_flags'=>['platform_exposure_to_browse_rate_mismatch']]);
        $summary = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_traffic'=>[$row]]);
        self::assertNull($summary['traffic']['avg_flow_rate']);
        self::assertContains('traffic_flow_rate_caliber_uncertain', array_column($summary['data_gaps'],'code'));
    }

    public function testFractionalAnomaliesRemainBlockedAfterCountsAreRounded(): void
    {
        $etl = new OtaStandardEtlService();
        $method = new \ReflectionMethod($etl, 'trafficFact');
        foreach ([-0.1, 100.1] as $badCount) {
            $bad = $method->invoke($etl, ['list_exposure'=>100,'detail_exposure'=>$badCount,
                'order_filling_num'=>100,'order_submit_num'=>$badCount], [], 'system:80','meituan','2026-08-01');
            $good = $method->invoke($etl, ['list_exposure'=>100,'detail_exposure'=>10,
                'order_filling_num'=>100,'order_submit_num'=>10], [], 'system:80','meituan','2026-08-02');
            $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_traffic'=>[$bad,$good]]);
            self::assertNull($result['traffic']['avg_flow_rate']);
            self::assertNull($result['traffic']['avg_submit_rate']);
            self::assertContains('traffic_flow_rate_counts_invalid', array_column($result['data_gaps'],'code'));
            self::assertContains('traffic_submit_rate_counts_invalid', array_column($result['data_gaps'],'code'));
        }
    }
}
