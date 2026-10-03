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

    public function testHistoricalScenariosNormalizeUncalculableRentRatioToNull(): void
    {
        $service = new FeasibilityReportService();
        $input = $this->validInput(['monthly_rent' => 0.0]);
        $modelReport = [
            'financial_scenarios' => [
                ['name' => 'Zero revenue', 'adr' => 0, 'occ' => 0.75, 'monthly_revenue' => 0, 'monthly_net_cashflow' => -100, 'payback_months' => null, 'rent_ratio' => 0, 'risk_level' => '低'],
                ['name' => 'Missing ratio', 'adr' => 300, 'occ' => 0.75, 'monthly_revenue' => 135000, 'monthly_net_cashflow' => 10000, 'payback_months' => 45, 'rent_ratio' => null, 'risk_level' => '低'],
            ],
        ];

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

    public function testReadinessKeepsManualOnlyReportOutOfInvestmentClosure(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput();
        $snapshot = ['source_counts' => [], 'daily_summary' => [], 'competitor_summary' => []];
        $calculation = RetiredHistoryFixture::result('feasibility', 'calculation');
        $report = RetiredHistoryFixture::result('feasibility', 'manual_report');

        $readiness = $service->buildFeasibilityReadiness($input, $snapshot, $report);

        self::assertSame('manual_input_only', $readiness['stage']);
        self::assertFalse($readiness['feasibility_ready']);
        self::assertContains('source_evidence', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testLegacyCompetitorPriceLogsDoNotQualifyAsInvestmentMarketEvidence(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        // Captured shape of legacy, unverified competitor price history.
        $summary = ['comparison_status' => 'reference_only', 'avg_competitor_price' => null];
        $snapshot = [
            'source_counts' => ['competitor_price_logs' => 2],
            'competitor_summary' => $summary,
        ];

        self::assertSame('reference_only', $summary['comparison_status']);
        self::assertNull($summary['avg_competitor_price']);
        self::assertFalse($this->invokeNonPublic($service, 'hasTraceableMarketEvidence', [$snapshot]));
        self::assertSame(0, $this->invokeNonPublic($service, 'sourceCountTotal', [$snapshot]));

        $truth = $this->invokeNonPublic($service, 'feasibilityMarketTruthContext', [
            ['status' => 'unverified', 'source' => []], $snapshot, [],
        ]);
        self::assertSame('unverified', $truth['status']);
    }

    public function testReadinessDoesNotTreatUnverifiedOpeningProjectIdAsTrackingClosure(): void
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
        $calculation = RetiredHistoryFixture::result('feasibility', 'calculation');
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

        self::assertSame('approved_pending_tracking', $readiness['stage']);
        self::assertFalse($readiness['feasibility_ready']);
        self::assertSame(94, $readiness['score']);
        self::assertContains('post_decision_tracking', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testFormattedRecordReturnsFeasibilityReadinessForListAndDetail(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $input = $this->validInput();
        $snapshot = ['source_counts' => [], 'daily_summary' => [], 'competitor_summary' => []];
        $calculation = RetiredHistoryFixture::result('feasibility', 'calculation');
        $report = RetiredHistoryFixture::result('feasibility', 'manual_report_c');

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
        $input = $this->validInput(['manual_review' => 'approved', 'hotel_id' => 3, 'system_hotel_id' => 3]);
        $snapshot = [
            'source_counts' => ['daily_reports' => 12, 'competitor_price_logs' => 5],
            'daily_summary' => ['avg_adr' => 310, 'avg_occ' => 0.76],
            'competitor_summary' => ['avg_competitor_price' => 300],
        ];
        $calculation = RetiredHistoryFixture::result('feasibility', 'calculation');
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

    public function testExecutionIntentRejectsHotelDifferentFromPersistedFeasibilityScope(): void
    {
        $service = new FeasibilityReportService($this->failingClient());
        $record = [
            'id' => 7,
            'input' => ['hotel_id' => 3, 'system_hotel_id' => 3],
            'snapshot' => ['snapshot_scope' => ['hotel_id' => 3]],
        ];

        self::assertSame(3, $service->executionHotelId($record));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feasibility report hotel scope mismatch');
        $service->assertExecutionHotelMatches($record, 4);
    }

    public function testExecutionIntentFailsClosedWhenPersistedFeasibilityHotelScopesConflict(): void
    {
        $service = new FeasibilityReportService($this->failingClient());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feasibility report hotel scope conflict');
        $service->executionHotelId([
            'input' => ['hotel_id' => 3],
            'snapshot' => ['snapshot_scope' => ['hotel_id' => 4]],
        ]);
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
