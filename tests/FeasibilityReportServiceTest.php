<?php
declare(strict_types=1);

namespace Tests;

use app\service\FeasibilityReportService;
use app\service\LlmClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class FeasibilityReportServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testNormalizeInputAndFinancialScenariosUseUserInvestmentAssumptions(): void
    {
        $service = new FeasibilityReportService($this->failingClient());

        $input = $this->invokeNonPublic($service, 'normalizeInput', [[
            'project_name' => '  Project A  ',
            'city' => ' Shanghai ',
            'system_hotel_id' => '17',
            'property_area' => '600.456',
            'room_count' => '20',
            'monthly_rent' => '40000',
            'lease_years' => '10',
            'decoration_budget' => '300000',
            'transfer_fee' => '100000',
            'opening_cost' => '50000',
            'adr' => '300',
            'occ' => '75',
        ]]);

        self::assertSame('Project A', $input['project_name']);
        self::assertSame(17, $input['hotel_id']);
        self::assertSame(600.46, $input['property_area']);
        self::assertSame(20.0, $input['room_count']);

        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, [
            'daily_summary' => ['avg_adr' => 0, 'avg_occ' => 0],
            'competitor_summary' => ['avg_competitor_price' => 0],
        ]]);
        $base = $calculation['scenarios'][1];

        self::assertSame(30.02, $calculation['area_per_room']);
        self::assertSame(50000.0, $calculation['opening_cost']);
        self::assertSame(450000.0, $calculation['total_investment']);
        self::assertSame(300.0, $base['adr']);
        self::assertSame(0.75, $base['occ']);
        self::assertSame(225.0, $base['revpar']);
        self::assertSame(135000.0, $base['monthly_revenue']);
        self::assertSame(105500.0, $base['monthly_operating_cost']);
        self::assertSame(29500.0, $base['monthly_net_cashflow']);
        self::assertSame(15.3, $base['payback_months']);
        self::assertTrue($calculation['decision_ready']);
        self::assertSame('rule_scenario_assumption', $calculation['cost_model']['basis']);
        self::assertFalse($calculation['cost_model']['is_actual_performance']);
        self::assertStringContainsString('非经营实绩', implode(' ', $calculation['assumptions']));
    }

    public function testZeroRevenueCannotBeReportedAsZeroRentRatio(): void
    {
        $service = new FeasibilityReportService($this->failingClient());

        $scenario = $this->invokeNonPublic($service, 'scenario', [
            'Zero revenue',
            20.0,
            0.0,
            0.75,
            $this->validInput(),
            450000.0,
            true,
        ]);

        self::assertSame(0.0, $scenario['monthly_revenue']);
        self::assertNull($scenario['rent_ratio']);
        self::assertSame('unavailable_non_positive_revenue', $scenario['rent_ratio_status']);
        self::assertContains('rent_ratio_denominator_non_positive', $scenario['data_gaps']);
        self::assertSame('rule_scenario_partial', $scenario['calculation_status']);
        self::assertSame('高', $scenario['risk_level']);
    }

    public function testExplicitZeroRentKeepsCalculatedZeroRatioWithPositiveRevenue(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput(['monthly_rent' => 0.0]);

        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, []]);
        $base = $calculation['scenarios'][1];

        self::assertGreaterThan(0, $base['monthly_revenue']);
        self::assertSame(0.0, $base['rent_ratio']);
        self::assertSame('calculated', $base['rent_ratio_status']);
        self::assertNotContains('rent_ratio_denominator_non_positive', $base['data_gaps']);
        self::assertSame('rule_scenario_ready', $base['calculation_status']);
        self::assertTrue($calculation['decision_ready']);
    }

    public function testModelAndHistoricalScenariosNormalizeUncalculableRentRatioToNull(): void
    {
        $client = new class extends LlmClient {
            public array $schema = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->schema = $schema;

                return [
                    'conclusion_grade' => 'B',
                    'conclusion_text' => 'Proceed after review',
                    'core_reason' => 'Model response',
                    'summary' => [],
                    'basic_info' => [],
                    'market_judgement' => [],
                    'financial_scenarios' => [
                        [
                            'name' => 'Zero revenue',
                            'adr' => 0,
                            'occ' => 0.75,
                            'monthly_revenue' => 0,
                            'monthly_net_cashflow' => -100,
                            'payback_months' => null,
                            'rent_ratio' => 0,
                            'risk_level' => '低',
                        ],
                        [
                            'name' => 'Missing ratio',
                            'adr' => 300,
                            'occ' => 0.75,
                            'monthly_revenue' => 135000,
                            'monthly_net_cashflow' => 10000,
                            'payback_months' => 45,
                            'rent_ratio' => null,
                            'risk_level' => '低',
                        ],
                    ],
                    'risk_list' => [],
                    'action_plan' => [],
                    'assumptions' => [],
                    'evidence' => [],
                ];
            }
        };
        $service = new FeasibilityReportService($client);
        $input = $this->validInput(['monthly_rent' => 0.0]);
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, []]);

        $modelReport = $this->invokeNonPublic($service, 'buildAiReport', [$input, [], $calculation]);

        self::assertSame(
            ['number', 'null'],
            $client->schema['properties']['financial_scenarios']['items']['properties']['rent_ratio']['type']
        );
        self::assertNull($modelReport['financial_scenarios'][0]['rent_ratio']);
        self::assertSame('unavailable_non_positive_revenue', $modelReport['financial_scenarios'][0]['rent_ratio_status']);
        self::assertSame('高', $modelReport['financial_scenarios'][0]['risk_level']);
        self::assertNull($modelReport['financial_scenarios'][1]['rent_ratio']);
        self::assertSame('unverified_missing_ratio', $modelReport['financial_scenarios'][1]['rent_ratio_status']);
        self::assertFalse($modelReport['decision_ready']);
        self::assertContains('rent_ratio_denominator_non_positive', $modelReport['data_gaps']);

        $merged = $this->invokeNonPublic($service, 'mergeFinancials', [$modelReport, $input, $calculation]);
        self::assertTrue($merged['decision_ready']);
        self::assertSame(0.0, $merged['financial_scenarios'][1]['rent_ratio']);
        self::assertSame('calculated', $merged['financial_scenarios'][1]['rent_ratio_status']);

        $historicalReport = $modelReport;
        $historicalReport['financial_scenarios'][1]['rent_ratio'] = 0;
        $record = $this->invokeNonPublic($service, 'formatArrayRecord', [[
            'id' => 8,
            'project_name' => 'Historical Project',
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'snapshot_json' => '[]',
            'report_json' => json_encode($historicalReport, JSON_UNESCAPED_UNICODE),
            'conclusion_grade' => 'B',
            'payback_months' => 45,
            'total_investment' => 450000,
        ], true]);

        self::assertNull($record['report']['financial_scenarios'][0]['rent_ratio']);
        self::assertSame(0.0, $record['report']['financial_scenarios'][1]['rent_ratio']);
        self::assertSame('calculated', $record['report']['financial_scenarios'][1]['rent_ratio_status']);
        self::assertFalse($record['decision_ready']);
        self::assertSame('unverified', $record['truth_context']['status']);
        self::assertSame('investment_scenario', $record['truth_context']['metric_scope']);
        self::assertSame('calculated', $record['metric_truth']['financial_scenarios.1.rent_ratio']['calculation_status']);
        self::assertSame('missing', $record['metric_truth']['financial_scenarios.0.rent_ratio']['calculation_status']);
        self::assertSame('user_input', $record['input_metric_truth']['monthly_rent']['calculation_basis']);
        self::assertSame('ota_channel', $record['ota_truth_context']['metric_scope']);
    }

    public function testSnapshotSummariesNormalizeDailyJsonAndCompetitorSamples(): void
    {
        $service = new FeasibilityReportService($this->failingClient());

        $daily = $this->invokeNonPublic($service, 'summarizeDailyReports', [[
            ['id' => 11, 'report_date' => '2026-07-17', 'created_at' => '2026-07-17 23:00:00', 'report_data' => json_encode(['day_adr' => 300, 'day_occ_rate' => 75, 'day_revenue' => 1000], JSON_UNESCAPED_UNICODE)],
            ['id' => 12, 'report_date' => '2026-07-18', 'created_at' => '2026-07-18 23:00:00', 'report_data' => ['adr' => 260, 'occ' => 0.5, 'revenue' => 900]],
            ['report_data' => '{bad-json'],
        ], 17]);
        $online = $this->invokeNonPublic($service, 'summarizeOnlineData', [[
            $this->trustedOnlineRow(1, 17),
            $this->trustedOnlineRow(2, 17, ['platform' => 'meituan', 'source' => 'meituan']),
        ], 17]);
        $legacyCompetitors = $this->invokeNonPublic($service, 'summarizeCompetitors', [
            [['id' => 1], ['id' => 2]],
            [['price' => 260], ['price' => 300], ['price' => 0]],
        ]);
        $competitors = $this->invokeNonPublic($service, 'summarizeCompetitors', [
            [['id' => 1], ['id' => 2]],
            [
                $this->comparableCompetitorPrice(260),
                $this->comparableCompetitorPrice(300, ['fetch_time' => '2026-07-17 10:05:00']),
                $this->comparableCompetitorPrice(999, ['check_in_date' => '2026-07-20', 'check_out_date' => '2026-07-21']),
            ],
        ]);

        self::assertSame(280.0, $daily['avg_adr']);
        self::assertSame(0.63, $daily['avg_occ']);
        self::assertSame(950.0, $daily['avg_revenue']);
        self::assertSame('unverified', $daily['truth_context']['status']);
        self::assertSame('whole_hotel_local_report', $daily['truth_context']['metric_scope']);
        self::assertSame(17, $daily['truth_context']['hotels'][0]['system_hotel_id']);
        self::assertSame('2026-07-17', $daily['truth_context']['date_range']['start']);
        self::assertSame('calculated', $daily['metric_truth']['avg_revenue']['calculation_status']);
        self::assertSame(2, $online['sample_count']);
        self::assertSame(2, $online['trusted_sample_count']);
        self::assertTrue($online['has_real_ota_data']);
        self::assertNull($legacyCompetitors['avg_competitor_price']);
        self::assertSame('reference_only', $legacyCompetitors['comparison_status']);
        self::assertSame(3, $legacyCompetitors['reference_only_price_count']);
        self::assertContains('strict_comparability_missing', $legacyCompetitors['data_gaps']);
        self::assertSame(2, $competitors['competitor_count']);
        self::assertSame(280.0, $competitors['avg_competitor_price']);
        self::assertSame('eligible', $competitors['comparison_status']);
        self::assertSame(2, $competitors['decision_eligible_price_count']);
        self::assertSame(1, $competitors['reference_only_price_count']);
        self::assertContains('mixed_comparison_key', $competitors['data_gaps']);

        $emptyDaily = $this->invokeNonPublic($service, 'summarizeDailyReports', [[]]);
        self::assertNull($emptyDaily['avg_adr']);
        self::assertNull($emptyDaily['avg_occ']);
        self::assertNull($emptyDaily['avg_revenue']);

        $zeroDaily = $this->invokeNonPublic($service, 'summarizeDailyReports', [[
            ['id' => 13, 'report_date' => '2026-07-19', 'report_data' => ['adr' => 0, 'occ' => 0, 'revenue' => 0]],
        ], 17]);
        self::assertSame(0.0, $zeroDaily['avg_adr']);
        self::assertSame(0.0, $zeroDaily['avg_occ']);
        self::assertSame(0.0, $zeroDaily['avg_revenue']);
        self::assertSame('calculated', $zeroDaily['metric_truth']['avg_revenue']['calculation_status']);
    }

    public function testSnapshotRowsDoNotMixHotelsWithinTheSameTenant(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $sameTenantRows = [
            ['id' => 1, 'tenant_id' => 9, 'hotel_id' => 101, 'system_hotel_id' => 101, 'store_id' => 101],
            ['id' => 2, 'tenant_id' => 9, 'hotel_id' => 202, 'system_hotel_id' => 202, 'store_id' => 202],
        ];

        foreach (['hotel_id', 'system_hotel_id', 'store_id'] as $hotelColumn) {
            $scoped = $this->invokeNonPublic($service, 'filterSnapshotRowsForHotel', [
                $sameTenantRows,
                $hotelColumn,
                101,
            ]);
            self::assertSame([1], array_column($scoped, 'id'), $hotelColumn . ' must isolate the target hotel');
        }

        $online = $this->invokeNonPublic($service, 'summarizeOnlineData', [[
            $this->trustedOnlineRow(11, 101),
            $this->trustedOnlineRow(12, 202),
        ], 101]);
        self::assertSame(1, $online['sample_count']);
        self::assertSame(2, $online['queried_sample_count']);
        self::assertSame(1, $online['trusted_sample_count']);
        self::assertSame(101, $online['evidence_rows'][0]['hotel']['system_hotel_id']);
    }

    public function testOnlineSnapshotRequiresTargetHotelAndTrustedReadbackFields(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $countOnly = $this->invokeNonPublic($service, 'summarizeOnlineData', [[
            ['id' => 1, 'system_hotel_id' => 17],
        ], 17]);
        self::assertFalse($countOnly['has_real_ota_data']);
        self::assertSame(0, $countOnly['trusted_sample_count']);
        self::assertContains('target_hotel_trusted_readback_rows_missing', $countOnly['data_gaps']);

        $unbound = $this->invokeNonPublic($service, 'buildSnapshot', [$this->validInput(), 9]);
        self::assertSame('unverified', $unbound['snapshot_scope']['status']);
        self::assertContains('target_hotel_missing', $unbound['data_gaps']);
        self::assertSame(0, array_sum($unbound['source_counts']));
        self::assertFalse($unbound['online_summary']['has_real_ota_data']);
    }

    public function testCalculateKeepsMissingCoreInputsNullWithoutHiddenDefaults(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->invokeNonPublic($service, 'normalizeInput', [[
            'project_name' => 'Extreme Rent',
            'property_area' => 300,
            'room_count' => 10,
            'monthly_rent' => 999999,
            'lease_years' => 5,
            'decoration_budget' => 0,
            'transfer_fee' => 0,
            'opening_cost' => '',
            'adr' => '',
            'occ' => '',
        ]]);

        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, [
            'daily_summary' => ['avg_adr' => 333, 'avg_occ' => 0.81],
            'competitor_summary' => ['avg_competitor_price' => 444],
        ]]);
        $base = $calculation['scenarios'][1];

        self::assertNull($input['opening_cost']);
        self::assertNull($input['adr']);
        self::assertNull($input['occ']);
        self::assertFalse($calculation['decision_ready']);
        self::assertSame('待评估', $calculation['evaluation_status']);
        self::assertContains('opening_cost_missing', $calculation['data_gaps']);
        self::assertContains('expected_adr_missing_or_invalid', $calculation['data_gaps']);
        self::assertContains('expected_occ_missing_or_invalid', $calculation['data_gaps']);
        self::assertNull($calculation['opening_cost']);
        self::assertNull($calculation['total_investment']);
        self::assertNull($base['adr']);
        self::assertNull($base['occ']);
        self::assertNull($base['monthly_revenue']);
        self::assertNull($base['monthly_net_cashflow']);
        self::assertNull($base['payback_months']);
        self::assertSame('待评估', $base['risk_level']);
        self::assertStringNotContainsString('260 元估算', implode(' ', $calculation['assumptions']));
        self::assertStringNotContainsString('72% 估算', implode(' ', $calculation['assumptions']));
        self::assertStringNotContainsString('2500 元/间', implode(' ', $calculation['assumptions']));
    }

    public function testExplicitZeroOpeningCostIsPreservedAsUserAssumption(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput(['opening_cost' => 0.0]);

        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, [
            'daily_summary' => ['avg_adr' => 999, 'avg_occ' => 0.99],
            'competitor_summary' => ['avg_competitor_price' => 999],
        ]]);

        self::assertTrue($calculation['decision_ready']);
        self::assertSame(0.0, $calculation['opening_cost']);
        self::assertSame(400000.0, $calculation['total_investment']);
        self::assertSame(300.0, $calculation['scenarios'][1]['adr']);
        self::assertSame(0.75, $calculation['scenarios'][1]['occ']);
    }

    public function testMissingCoreInputsBypassLlmAndReturnPendingEvaluation(): void
    {
        $client = new class extends LlmClient {
            public int $calls = 0;

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->calls++;
                throw new RuntimeException('LLM must not run for incomplete investment inputs');
            }
        };
        $service = new FeasibilityReportService($client);
        $input = $this->invokeNonPublic($service, 'normalizeInput', [[
            'project_name' => 'Pending Project',
            'property_area' => 300,
            'room_count' => 10,
            'monthly_rent' => 20000,
            'lease_years' => 8,
            'decoration_budget' => 200000,
            'transfer_fee' => 0,
            'opening_cost' => '',
            'adr' => '',
            'occ' => '',
        ]]);
        $snapshot = [
            'source_counts' => ['daily_reports' => 30, 'competitor_price_logs' => 20],
            'daily_summary' => ['avg_adr' => 888, 'avg_occ' => 0.88],
            'competitor_summary' => ['avg_competitor_price' => 777],
        ];
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, $snapshot]);
        $report = $this->invokeNonPublic($service, 'buildAiReport', [$input, $snapshot, $calculation]);
        $report = $this->invokeNonPublic($service, 'mergeFinancials', [$report, $input, $calculation]);
        $readiness = $service->buildFeasibilityReadiness($input, $snapshot, $report);

        self::assertSame(0, $client->calls);
        self::assertFalse($report['decision_ready']);
        self::assertSame('待评估', $report['evaluation_status']);
        self::assertNull($report['conclusion_grade']);
        self::assertNull($report['summary']['payback_months']);
        self::assertContains('opening_cost_missing', $report['data_gaps']);
        self::assertStringContainsString('未生成回本期或结论等级', $report['core_reason']);
        self::assertSame('input_pending', $readiness['stage']);
        self::assertFalse($readiness['decision_ready']);
        self::assertSame('待评估', $readiness['status_label']);
    }

    public function testBuildAiReportUsesStubbedLlmPayloadAndModelKey(): void
    {
        $client = new class extends LlmClient {
            public array $messages = [];
            public array $schema = [];
            public string $modelKey = '';

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;
                $this->schema = $schema;
                $this->modelKey = $modelKey;

                return [
                    'conclusion_grade' => 'B',
                    'conclusion_text' => 'Proceed after review',
                    'core_reason' => 'Cashflow acceptable',
                    'summary' => [],
                    'basic_info' => [],
                    'market_judgement' => [],
                    'financial_scenarios' => [],
                    'risk_list' => [],
                    'action_plan' => [],
                    'assumptions' => ['stubbed'],
                    'evidence' => [],
                ];
            }
        };
        $service = new FeasibilityReportService($client);
        $input = $this->validInput(['model_key' => 'openai_fast']);
        $snapshot = ['daily_summary' => ['avg_adr' => 280, 'avg_occ' => 0.7], 'competitor_summary' => ['avg_competitor_price' => 300]];
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, $snapshot]);

        $report = $this->invokeNonPublic($service, 'buildAiReport', [$input, $snapshot, $calculation]);
        $payload = json_decode((string)$client->messages[1]['content'], true);

        self::assertSame('openai_fast', $client->modelKey);
        self::assertArrayHasKey('x-governance', $client->schema);
        self::assertEquals($input, $payload['user_input']);
        self::assertEquals($snapshot, $payload['system_snapshot']);
        self::assertEquals($calculation, $payload['deterministic_calculation']);
        self::assertSame('B', $report['conclusion_grade']);
        self::assertStringContainsString('reference_only', $client->messages[0]['content']);
        self::assertStringContainsString('不得与项目 ADR 比较', $client->messages[0]['content']);

        $merged = $this->invokeNonPublic($service, 'mergeFinancials', [$report, $input, $calculation]);
        self::assertSame($calculation['scenarios'], $merged['financial_scenarios']);
        self::assertContains('stubbed', $merged['assumptions']);
    }

    public function testBuildAiReportFallsBackWhenStubbedLlmFails(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput();
        $snapshot = ['source_counts' => [], 'daily_summary' => [], 'competitor_summary' => []];
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, $snapshot]);

        $report = $this->invokeNonPublic($service, 'buildAiReport', [$input, $snapshot, $calculation]);

        self::assertContains($report['conclusion_grade'], ['A', 'B', 'C', 'D']);
        self::assertSame($calculation['scenarios'], $report['financial_scenarios']);
        self::assertNotEmpty($report['assumptions']);
        self::assertSame('local_calculation', $report['evidence'][0]['source']);
    }

    public function testFallbackKeepsMissingMarketFactsAndPaybackUnknown(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput([
            'target_brand_level' => '',
            'target_customer' => '',
            'monthly_rent' => 999999.0,
        ]);
        $snapshot = [
            'source_counts' => ['daily_reports' => 1, 'online_daily_data' => 1],
            'daily_summary' => [],
            'competitor_summary' => [],
        ];
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, $snapshot]);

        $report = $this->invokeNonPublic($service, 'buildAiReport', [$input, $snapshot, $calculation]);
        $report = $this->invokeNonPublic($service, 'mergeFinancials', [$report, $input, $calculation]);

        self::assertNull($report['summary']['payback_months']);
        self::assertNull($report['market_judgement']['market_score']);
        self::assertSame('未评估', $report['market_judgement']['competition_level']);
        self::assertNull($report['market_judgement']['recommended_model']);
        self::assertNull($report['market_judgement']['target_customer']);
        self::assertSame('待核验', $report['risk_list'][1]['level']);
        self::assertStringContainsString('记录存在不等于来源', $report['risk_list'][1]['reason']);

        $positiveInput = $this->validInput(['target_brand_level' => '', 'target_customer' => '']);
        $positiveCalculation = $this->invokeNonPublic($service, 'calculate', [$positiveInput, $snapshot]);
        $positiveReport = $this->invokeNonPublic($service, 'buildAiReport', [$positiveInput, $snapshot, $positiveCalculation]);
        self::assertSame('待核验', $this->invokeNonPublic($service, 'feasibilityRiskLevel', [$positiveReport]));
    }

    public function testReadinessKeepsManualOnlyReportOutOfInvestmentClosure(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput();
        $snapshot = ['source_counts' => [], 'daily_summary' => [], 'competitor_summary' => []];
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, $snapshot]);
        $report = $this->invokeNonPublic($service, 'buildAiReport', [$input, $snapshot, $calculation]);
        $report = $this->invokeNonPublic($service, 'mergeFinancials', [$report, $input, $calculation]);

        $readiness = $service->buildFeasibilityReadiness($input, $snapshot, $report);

        self::assertSame('manual_input_only', $readiness['stage']);
        self::assertFalse($readiness['feasibility_ready']);
        self::assertContains('source_evidence', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testLegacyCompetitorPriceLogsDoNotQualifyAsInvestmentMarketEvidence(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $summary = $this->invokeNonPublic($service, 'summarizeCompetitors', [
            [],
            [['price' => 260], ['price' => 300]],
        ]);
        $snapshot = [
            'source_counts' => ['competitor_price_logs' => 2],
            'competitor_summary' => $summary,
        ];

        self::assertSame('reference_only', $summary['comparison_status']);
        self::assertNull($summary['avg_competitor_price']);
        self::assertFalse($this->invokeNonPublic($service, 'hasTraceableMarketEvidence', [$snapshot]));
        self::assertSame(0, $this->invokeNonPublic($service, 'sourceCountTotal', [$snapshot]));

        $bounded = $this->invokeNonPublic($service, 'enforceMarketEvidenceBoundary', [[
            'market_judgement' => [
                'market_score' => 88,
                'competition_level' => '高',
                'reasoning' => 'legacy price was high',
            ],
        ], $this->validInput(), $snapshot]);
        self::assertNull($bounded['market_judgement']['market_score']);
        self::assertSame('未评估', $bounded['market_judgement']['competition_level']);
    }

    public function testReadinessRequiresEvidenceReviewAndTrackingForFeasibilityClosure(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput([
            'manual_review' => 'approved',
            'execution_tracking' => ['opening_project_id' => 8],
        ]);
        $snapshot = [
            'source_counts' => ['daily_reports' => 12, 'competitor_price_logs' => 5],
            'daily_summary' => ['avg_adr' => 310, 'avg_occ' => 0.76],
            'competitor_summary' => ['avg_competitor_price' => 300],
        ];
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, $snapshot]);
        $report = [
            'conclusion_grade' => 'B',
            'conclusion_text' => 'Proceed after review',
            'core_reason' => 'Cashflow acceptable with source evidence',
            'summary' => [
                'project_name' => $input['project_name'],
                'location' => 'Shanghai Pudong No.1',
                'room_count' => 20,
                'total_investment' => $calculation['total_investment'],
                'payback_months' => 18,
            ],
            'financial_scenarios' => $calculation['scenarios'],
            'risk_list' => [
                ['risk' => 'cashflow', 'level' => '低', 'reason' => 'positive cashflow', 'action' => 'review monthly'],
            ],
            'evidence' => [
                ['source' => 'market_survey', 'title' => 'site survey', 'url' => 'https://example.test/evidence', 'summary' => 'verified'],
            ],
            'diligence_evidence' => ['lease_review' => 'passed'],
        ];

        $readiness = $service->buildFeasibilityReadiness($input, $snapshot, $report);

        self::assertSame('feasibility_ready', $readiness['stage']);
        self::assertTrue($readiness['feasibility_ready']);
        self::assertSame(100, $readiness['score']);
    }

    public function testFormattedRecordReturnsFeasibilityReadinessForListAndDetail(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput();
        $snapshot = ['source_counts' => [], 'daily_summary' => [], 'competitor_summary' => []];
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, $snapshot]);
        $report = $this->invokeNonPublic($service, 'mergeFinancials', [
            [
                'conclusion_grade' => 'C',
                'conclusion_text' => 'Needs review',
                'core_reason' => 'Manual assumptions only',
                'summary' => [],
                'assumptions' => [],
                'financial_scenarios' => [],
                'risk_list' => [],
                'evidence' => [],
            ],
            $input,
            $calculation,
        ]);

        $record = $this->invokeNonPublic($service, 'formatArrayRecord', [[
            'id' => 7,
            'project_name' => 'Valid Project',
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE),
            'conclusion_grade' => 'C',
            'payback_months' => 22,
            'total_investment' => 450000,
            'created_at' => '2026-06-14 10:00:00',
            'updated_at' => '2026-06-14 10:00:00',
        ], true]);

        self::assertSame('Shanghai', $record['city']);
        self::assertArrayHasKey('feasibility_readiness', $record);
        self::assertSame('manual_input_only', $record['feasibility_readiness']['stage']);
        self::assertArrayHasKey('report', $record);
    }

    public function testFormattedLegacyRecordCannotExposeGradeOrPaybackWhenCoreInputsAreMissing(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput([
            'opening_cost' => null,
            'adr' => null,
            'occ' => null,
        ]);
        $report = [
            'conclusion_grade' => 'A',
            'conclusion_text' => 'Legacy generated conclusion',
            'summary' => ['total_investment' => 450000, 'payback_months' => 12],
            'financial_scenarios' => [[], [], []],
            'risk_list' => [],
        ];

        $record = $this->invokeNonPublic($service, 'formatArrayRecord', [[
            'id' => 99,
            'project_name' => 'Legacy Pending Project',
            'input_json' => $input,
            'snapshot_json' => [],
            'report_json' => $report,
            'conclusion_grade' => 'A',
            'payback_months' => 12,
            'total_investment' => 450000,
        ], true]);

        self::assertFalse($record['decision_ready']);
        self::assertSame('待评估', $record['evaluation_status']);
        self::assertNull($record['conclusion_grade']);
        self::assertNull($record['payback_months']);
        self::assertContains('opening_cost_missing', $record['data_gaps']);
        self::assertSame('input_pending', $record['feasibility_readiness']['stage']);
    }

    public function testBuildExecutionIntentInputRequiresExplicitHotel(): void
    {
        $service = new FeasibilityReportService($this->failingClient());

        $this->expectException(\InvalidArgumentException::class);
        $service->buildExecutionIntentInput(['id' => 7], 0);
    }

    public function testBuildExecutionIntentRejectsPendingEvaluation(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput(['opening_cost' => null, 'adr' => null, 'occ' => null]);
        $report = [
            'decision_ready' => false,
            'data_gaps' => ['opening_cost_missing', 'expected_adr_missing_or_invalid', 'expected_occ_missing_or_invalid'],
            'conclusion_grade' => null,
            'conclusion_text' => '待评估',
            'financial_scenarios' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('待评估报告不能转投后跟踪');
        $service->buildExecutionIntentInput([
            'id' => 7,
            'input' => $input,
            'snapshot' => [],
            'report' => $report,
        ], 3);
    }

    public function testBuildExecutionIntentInputCarriesReadinessAndInvestmentScope(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput(['manual_review' => 'approved']);
        $snapshot = [
            'source_counts' => ['daily_reports' => 12, 'competitor_price_logs' => 5],
            'daily_summary' => ['avg_adr' => 310, 'avg_occ' => 0.76],
            'competitor_summary' => ['avg_competitor_price' => 300],
        ];
        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, $snapshot]);
        $report = [
            'conclusion_grade' => 'B',
            'conclusion_text' => 'Proceed after review',
            'core_reason' => 'Cashflow acceptable with source evidence',
            'summary' => [
                'project_name' => $input['project_name'],
                'location' => 'Shanghai Pudong No.1',
                'room_count' => 20,
                'total_investment' => $calculation['total_investment'],
                'payback_months' => 18,
            ],
            'financial_scenarios' => $calculation['scenarios'],
            'risk_list' => [
                ['risk' => 'cashflow', 'level' => 'low', 'reason' => 'positive cashflow', 'action' => 'review monthly'],
            ],
            'evidence' => [
                ['source' => 'market_survey', 'title' => 'site survey', 'url' => 'https://example.test/evidence', 'summary' => 'verified'],
            ],
            'diligence_evidence' => ['lease_review' => 'passed'],
        ];
        $readiness = $service->buildFeasibilityReadiness($input, $snapshot, $report);

        $intentInput = $service->buildExecutionIntentInput([
            'id' => 7,
            'project_name' => $input['project_name'],
            'input' => $input,
            'snapshot' => $snapshot,
            'report' => $report,
            'feasibility_readiness' => $readiness,
            'conclusion_grade' => 'B',
            'payback_months' => 18,
            'total_investment' => $calculation['total_investment'],
        ], 3, ['date_start' => '2026-06-14']);

        self::assertSame('feasibility_report', $intentInput['source_module']);
        self::assertSame(7, $intentInput['source_record_id']);
        self::assertSame(3, $intentInput['hotel_id']);
        self::assertSame('investment', $intentInput['platform']);
        self::assertSame('investment', $intentInput['object_type']);
        self::assertSame('investment_decision_closure', $intentInput['target_value']['target_metric']);
        self::assertSame('approved_pending_tracking', $intentInput['evidence']['readiness_stage']);
        self::assertSame('medium', $intentInput['risk_level']);
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

    private function trustedOnlineRow(int $id, int $hotelId, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'tenant_id' => 9,
            'system_hotel_id' => $hotelId,
            'hotel_id' => 'ota-' . $hotelId,
            'hotel_name' => 'Hotel ' . $hotelId,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_date' => '2026-07-18',
            'ingestion_method' => 'browser_profile',
            'snapshot_time' => '2026-07-18 09:30:00',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'create_time' => '2026-07-18 09:31:00',
            'update_time' => '2026-07-18 09:31:00',
        ], $overrides);
    }

    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'project_name' => 'Valid Project',
            'city' => 'Shanghai',
            'district' => 'Pudong',
            'address' => 'No.1',
            'target_brand_level' => 'midscale',
            'target_customer' => 'business',
            'notes' => '',
            'model_key' => '',
            'property_area' => 600.0,
            'room_count' => 20.0,
            'monthly_rent' => 40000.0,
            'lease_years' => 10.0,
            'decoration_budget' => 300000.0,
            'transfer_fee' => 100000.0,
            'opening_cost' => 50000.0,
            'adr' => 300.0,
            'occ' => 0.75,
        ], $overrides);
    }

    private function failingClient(): LlmClient
    {
        return new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('stubbed llm failure');
            }
        };
    }
}
