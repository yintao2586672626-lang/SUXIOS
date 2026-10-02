<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaInsightAnalysisService;
use app\service\OtaRevenueMetricService;
use PHPUnit\Framework\TestCase;

final class OtaInsightMetricTruthBoundaryTest extends TestCase
{
    public function testUnverifiedTrafficCannotBecomeAnActionableInsightWhenRevenueIsVerified(): void
    {
        $date = '2026-08-01';
        $trace = static function (int $rowId, string $dataType, bool $verified) use ($date): array {
            return [
                'table' => 'online_daily_data',
                'row_id' => $rowId,
                'source_trace_id' => 'ctrip:' . $rowId . ':' . $date,
                'hotel_key' => 'system:7',
                'system_hotel_id' => 7,
                'platform_hotel_id' => 'ctrip-hotel-7',
                'platform' => 'ctrip',
                'data_type' => $dataType,
                'date_key' => $date,
                'ingestion_method' => 'browser_profile',
                'collected_at' => $date . ' 09:55:00',
                'updated_at' => $date . ' 10:00:00',
                'data_period' => 'historical_daily',
                'is_final' => true,
                'stored' => true,
                'readback_verified' => $verified,
                'saved_success' => $verified,
                'failure_reasons' => $verified ? [] : ['traffic_readback_unverified'],
            ];
        };
        $dataset = [
            'status' => 'ready',
            'data_quality' => ['input_rows' => 2, 'accepted_rows' => 2, 'rejected_rows' => []],
            'fact_ota_daily' => [[
                'hotel_key' => 'system:7', 'platform_key' => 'ctrip', 'date_key' => $date,
                'data_type' => 'business', 'revenue' => 1200.0, 'room_revenue' => 1200.0,
                'room_nights' => 6.0, 'source_trace' => $trace(1, 'business', true),
            ]],
            'fact_ota_traffic' => [[
                'hotel_key' => 'system:7', 'platform_key' => 'ctrip', 'date_key' => $date,
                'list_exposure' => 100, 'detail_exposure' => 10,
                'source_trace' => $trace(2, 'traffic', false),
            ]],
        ];

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);
        $modules = array_column($analysis['modules'], null, 'key');

        self::assertSame(10.0, $metrics['traffic']['avg_flow_rate']);
        self::assertSame('verified', $metrics['metric_trust']['totals.adr']['truth']['status']);
        self::assertNotSame('verified', $metrics['metric_trust']['traffic.avg_flow_rate']['truth']['status']);
        self::assertSame('warning', $metrics['credibility_gate']['status']);
        self::assertSame('blocked_by_metric_truth', $modules['traffic_conversion']['status']);
        self::assertFalse($modules['traffic_conversion']['actionable']);
        self::assertContains('traffic.avg_flow_rate', $modules['traffic_conversion']['untrusted_metric_keys']);

        $dataset['fact_ota_traffic'][0]['detail_exposure'] = 0;
        $dataset['fact_ota_traffic'][0]['source_trace'] = $trace(2, 'traffic', true);
        $verifiedMetrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $verifiedAnalysis = (new OtaInsightAnalysisService())->analyzeMetrics($verifiedMetrics);
        $verifiedModules = array_column($verifiedAnalysis['modules'], null, 'key');
        self::assertSame(0.0, $verifiedMetrics['traffic']['avg_flow_rate']);
        self::assertSame('verified', $verifiedMetrics['metric_trust']['traffic.avg_flow_rate']['truth']['status']);
        self::assertNull($verifiedMetrics['traffic']['avg_submit_rate']);
        self::assertSame('partial_data', $verifiedModules['traffic_conversion']['status']);
        self::assertFalse($verifiedModules['traffic_conversion']['actionable']);
        self::assertContains('traffic_submit_rate_missing', $verifiedModules['traffic_conversion']['data_gaps']);

        $dataset['fact_ota_traffic'][0]['order_filling_num'] = 100;
        $dataset['fact_ota_traffic'][0]['order_submit_num'] = 30;
        $completeMetrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $completeAnalysis = (new OtaInsightAnalysisService())->analyzeMetrics($completeMetrics);
        $completeModules = array_column($completeAnalysis['modules'], null, 'key');
        self::assertSame(0.0, $completeMetrics['traffic']['avg_flow_rate']);
        self::assertSame(30.0, $completeMetrics['traffic']['avg_submit_rate']);
        self::assertSame('watch', $completeModules['traffic_conversion']['status']);

        $dataset['fact_ota_traffic'] = [];
        $missingMetrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $missingAnalysis = (new OtaInsightAnalysisService())->analyzeMetrics($missingMetrics);
        $missingModules = array_column($missingAnalysis['modules'], null, 'key');
        self::assertNull($missingMetrics['traffic']['avg_flow_rate']);
        self::assertSame('missing_data', $missingModules['traffic_conversion']['status']);
    }

    public function testUntrustedOptionalMetricRequiresReviewEvenWhenOverallGateIsReady(): void
    {
        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics([
            'status' => 'ready',
            'traffic' => ['avg_flow_rate' => 10.0, 'avg_submit_rate' => null],
            'metric_trust' => [
                'traffic.avg_flow_rate' => [
                    'saved_success' => false,
                    'failure_reasons' => ['traffic_readback_unverified'],
                    'truth' => ['status' => 'unverified'],
                ],
            ],
            'credibility_gate' => ['status' => 'ready', 'reason_codes' => []],
        ]);

        $modules = array_column($analysis['modules'], null, 'key');
        self::assertSame('ready_with_data_warnings', $analysis['status']);
        self::assertTrue($analysis['human_review_required']);
        self::assertSame('blocked_by_metric_truth', $modules['traffic_conversion']['status']);
        self::assertContains('traffic_readback_unverified', $modules['traffic_conversion']['blocking_reason_codes']);
    }

    public function testEitherMissingTrafficStageRemainsPartialWhenTheOtherStageIsVerified(): void
    {
        foreach ([
            'flow' => ['avg_flow_rate' => 0.0, 'avg_submit_rate' => null, 'missing' => 'traffic_submit_rate_missing'],
            'submit' => ['avg_flow_rate' => null, 'avg_submit_rate' => 30.0, 'missing' => 'traffic_flow_rate_missing'],
        ] as $case) {
            $trust = [];
            foreach (['avg_flow_rate', 'avg_submit_rate'] as $field) {
                if ($case[$field] !== null) {
                    $trust['traffic.' . $field] = [
                        'saved_success' => true,
                        'failure_reasons' => [],
                        'truth' => ['status' => 'verified'],
                    ];
                }
            }
            $analysis = (new OtaInsightAnalysisService())->analyzeMetrics([
                'status' => 'ready',
                'traffic' => [
                    'avg_flow_rate' => $case['avg_flow_rate'],
                    'avg_submit_rate' => $case['avg_submit_rate'],
                ],
                'metric_trust' => $trust,
                'credibility_gate' => ['status' => 'ready', 'reason_codes' => []],
            ]);
            $module = array_column($analysis['modules'], null, 'key')['traffic_conversion'];
            self::assertSame('partial_data', $module['status']);
            self::assertFalse($module['actionable']);
            self::assertContains($case['missing'], $module['data_gaps']);
            self::assertSame('ready_with_data_warnings', $analysis['status']);
            self::assertTrue($analysis['human_review_required']);
        }
    }
}
