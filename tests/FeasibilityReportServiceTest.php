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

    public function testSnapshotSummariesNormalizeDailyJsonAndCompetitorSamples(): void
    {
        $service = new FeasibilityReportService($this->failingClient());

        $daily = $this->invokeNonPublic($service, 'summarizeDailyReports', [[
            ['report_data' => json_encode(['day_adr' => 300, 'day_occ_rate' => 75, 'day_revenue' => 1000], JSON_UNESCAPED_UNICODE)],
            ['report_data' => ['adr' => 260, 'occ' => 0.5, 'revenue' => 900]],
            ['report_data' => '{bad-json'],
        ]]);
        $online = $this->invokeNonPublic($service, 'summarizeOnlineData', [[[1], [2]]]);
        $competitors = $this->invokeNonPublic($service, 'summarizeCompetitors', [
            [['id' => 1], ['id' => 2]],
            [['price' => 260], ['price' => 300], ['price' => 0]],
        ]);

        self::assertSame(280.0, $daily['avg_adr']);
        self::assertSame(0.63, $daily['avg_occ']);
        self::assertSame(950.0, $daily['avg_revenue']);
        self::assertSame(2, $online['sample_count']);
        self::assertTrue($online['has_real_ota_data']);
        self::assertSame(2, $competitors['competitor_count']);
        self::assertSame(280.0, $competitors['avg_competitor_price']);

        $emptyDaily = $this->invokeNonPublic($service, 'summarizeDailyReports', [[]]);
        self::assertNull($emptyDaily['avg_adr']);
        self::assertNull($emptyDaily['avg_occ']);
        self::assertNull($emptyDaily['avg_revenue']);
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
