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
            'opening_cost' => '',
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
    }

    public function testCalculateUsesConservativeDefaultsForEmptySnapshotAndExtremeRent(): void
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
            'opening_cost' => 0,
            'adr' => 0,
            'occ' => 0,
        ]]);

        $calculation = $this->invokeNonPublic($service, 'calculate', [$input, [
            'daily_summary' => ['avg_adr' => 0, 'avg_occ' => 0],
            'competitor_summary' => ['avg_competitor_price' => 0],
        ]]);
        $base = $calculation['scenarios'][1];

        self::assertSame(25000.0, $calculation['opening_cost']);
        self::assertSame(25000.0, $calculation['total_investment']);
        self::assertSame(260.0, $base['adr']);
        self::assertSame(0.72, $base['occ']);
        self::assertSame(56160.0, $base['monthly_revenue']);
        self::assertLessThan(0, $base['monthly_net_cashflow']);
        self::assertNull($base['payback_months']);
        self::assertNotEmpty($calculation['assumptions']);
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
        self::assertSame('system', $report['evidence'][0]['source']);
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
