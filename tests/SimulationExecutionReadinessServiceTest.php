<?php
declare(strict_types=1);

namespace Tests;

use app\service\SimulationExecutionReadinessService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;

final class SimulationExecutionReadinessServiceTest extends TestCase
{
    public function testQuantReadinessKeepsManualModelAsInputOnly(): void
    {
        $service = new SimulationExecutionReadinessService();

        $readiness = $service->buildQuantReadiness(
            $this->quantInput(),
            $this->quantResult(),
            $this->quantScenarios(),
            []
        );

        self::assertSame('manual_input_only', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
        self::assertContains('source_evidence', array_column($readiness['missing_evidence'], 'code'));
        self::assertContains('manual_review', array_column($readiness['missing_evidence'], 'code'));
        self::assertContains('execution_bridge', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testQuantReadinessRequiresEvidenceReviewAndExecutionBridge(): void
    {
        $service = new SimulationExecutionReadinessService();
        $input = array_merge($this->quantInput(), [
            'source_evidence' => ['daily_report' => 'checked'],
            'review_status' => 'approved',
            'operation_execution_intent_id' => 123,
        ]);

        $readiness = $service->buildQuantReadiness(
            $input,
            $this->quantResult(),
            $this->quantScenarios(),
            []
        );

        self::assertSame('execution_ready', $readiness['stage']);
        self::assertTrue($readiness['execution_ready']);
        self::assertSame([], $readiness['missing_evidence']);
    }

    public function testStrategyReadinessUsesSourceSnapshotButStillRequiresReviewAndExecution(): void
    {
        $service = new SimulationExecutionReadinessService();

        $readiness = $service->buildStrategyReadiness(
            ['project_name' => '虹桥新店'],
            ['total_score' => 82, 'items' => []],
            ['decision' => '建议推进', 'decision_direction' => '先复核竞品和租约'],
            ['risk_level' => '中风险'],
            ['local_data_used' => true, 'source_summary' => ['daily_reports', 'online_daily_data']]
        );

        self::assertSame('review_ready', $readiness['stage']);
        self::assertTrue($readiness['ready_for_review']);
        self::assertFalse($readiness['execution_ready']);
        self::assertContains('manual_review', array_column($readiness['missing_evidence'], 'code'));
        self::assertContains('execution_bridge', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testSummaryAggregatesStrategyAndQuantRows(): void
    {
        $service = new SimulationExecutionReadinessService();
        $summary = $service->readinessSummaryFromRows([
            [
                'input_json' => json_encode(['project_name' => '虹桥新店'], JSON_UNESCAPED_UNICODE),
                'score_json' => json_encode(['total_score' => 82, 'items' => []], JSON_UNESCAPED_UNICODE),
                'recommendation_json' => json_encode(['decision' => '建议推进', 'decision_direction' => '先复核'], JSON_UNESCAPED_UNICODE),
                'risk_json' => json_encode(['risk_level' => '中风险'], JSON_UNESCAPED_UNICODE),
                'data_snapshot_json' => json_encode(['local_data_used' => true, 'source_summary' => ['daily_reports']], JSON_UNESCAPED_UNICODE),
            ],
        ], [
            [
                'input_json' => json_encode($this->quantInput(), JSON_UNESCAPED_UNICODE),
                'result_json' => json_encode($this->quantResult(), JSON_UNESCAPED_UNICODE),
                'scenarios_json' => json_encode($this->quantScenarios(), JSON_UNESCAPED_UNICODE),
                'risk_hints_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertSame(2, $summary['record_count']);
        self::assertSame(1, $summary['review_ready_count']);
        self::assertSame(0, $summary['execution_ready_count']);
        self::assertNotSame('', $summary['best_status_label']);
    }

    public function testSummaryUsesTopLevelExecutionBridgeFromRows(): void
    {
        $service = new SimulationExecutionReadinessService();
        $summary = $service->readinessSummaryFromRows([
            [
                'execution_intent_id' => 321,
                'input_json' => json_encode([
                    'project_name' => 'Bridge Strategy',
                    'source_evidence' => ['site_visit' => 'checked'],
                    'review_status' => 'approved',
                ], JSON_UNESCAPED_UNICODE),
                'score_json' => json_encode(['total_score' => 82, 'items' => []], JSON_UNESCAPED_UNICODE),
                'recommendation_json' => json_encode(['decision' => 'go', 'decision_direction' => 'review lease'], JSON_UNESCAPED_UNICODE),
                'risk_json' => json_encode(['risk_level' => 'medium'], JSON_UNESCAPED_UNICODE),
                'data_snapshot_json' => json_encode(['local_data_used' => true, 'source_summary' => ['daily_reports']], JSON_UNESCAPED_UNICODE),
            ],
        ], [
            [
                'execution_intent_id' => 322,
                'input_json' => json_encode(array_merge($this->quantInput(), [
                    'source_evidence' => ['daily_report' => 'checked'],
                    'review_status' => 'approved',
                ]), JSON_UNESCAPED_UNICODE),
                'result_json' => json_encode($this->quantResult(), JSON_UNESCAPED_UNICODE),
                'scenarios_json' => json_encode($this->quantScenarios(), JSON_UNESCAPED_UNICODE),
                'risk_hints_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertSame(2, $summary['record_count']);
        self::assertSame(2, $summary['execution_ready_count']);
        self::assertSame('execution_ready', $summary['best_stage']);
    }

    public function testStrategyRecordBuildsCanonicalExecutionIntentInput(): void
    {
        $service = new SimulationExecutionReadinessService();

        $input = $service->buildStrategyExecutionIntentInput([
            'id' => 88,
            'project_name' => '虹桥新店',
            'input' => [
                'project_name' => '虹桥新店',
                'source_evidence' => ['site_visit' => 'checked'],
            ],
            'scores' => ['total_score' => 82, 'items' => []],
            'recommendation' => [
                'decision' => '建议推进',
                'decision_direction' => '先复核竞品和租约',
            ],
            'risk' => ['risk_level' => '中风险'],
            'data_snapshot' => ['local_data_used' => true, 'source_summary' => ['daily_reports']],
        ], [
            'hotel_id' => 7,
            'date_start' => '2026-06-25',
        ]);

        self::assertSame('strategy_simulation', $input['source_module']);
        self::assertSame(88, $input['source_record_id']);
        self::assertSame('investment', $input['object_type']);
        self::assertSame('strategy_review', $input['action_type']);
        self::assertSame('strategy_simulation_closure', $input['target_value']['target_metric']);
        self::assertSame('review_ready', $input['evidence']['readiness_stage']);

        $payload = (new OperationManagementService())->buildExecutionIntentPayload([7], 7, $input, 3);
        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
    }

    public function testQuantRecordExecutionIntentIsBlockedWithoutSourceEvidence(): void
    {
        $service = new SimulationExecutionReadinessService();

        $input = $service->buildQuantExecutionIntentInput([
            'id' => 89,
            'project_name' => '量化测算项目',
            'input' => $this->quantInput(),
            'result' => $this->quantResult(),
            'scenarios' => $this->quantScenarios(),
            'risk_hints' => [],
        ], [
            'hotel_id' => 7,
            'date_start' => '2026-06-25',
        ]);

        self::assertSame('quant_simulation', $input['source_module']);
        self::assertSame('manual_input_only', $input['evidence']['readiness_stage']);
        self::assertNotEmpty($input['evidence']['data_gaps']);

        $payload = (new OperationManagementService())->buildExecutionIntentPayload([7], 7, $input, 3);
        self::assertSame('blocked', $payload['status']);
        self::assertStringContainsString('manual_input_only', $payload['blocked_reason']);
    }

    public function testEmptyExecutionDateOverrideFallsBackToToday(): void
    {
        $service = new SimulationExecutionReadinessService();

        $input = $service->buildStrategyExecutionIntentInput([
            'id' => 90,
            'project_name' => '虹桥新店',
            'input' => [
                'project_name' => '虹桥新店',
                'source_evidence' => ['site_visit' => 'checked'],
            ],
            'scores' => ['total_score' => 82, 'items' => []],
            'recommendation' => [
                'decision' => '建议推进',
                'decision_direction' => '先复核竞品和租约',
            ],
            'risk' => ['risk_level' => '中风险'],
            'data_snapshot' => ['local_data_used' => true, 'source_summary' => ['daily_reports']],
        ], [
            'hotel_id' => 7,
            'date_start' => '',
            'date_end' => '',
        ]);

        $payload = (new OperationManagementService())->buildExecutionIntentPayload([7], 7, $input, 3);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $payload['date_start']);
        self::assertSame($payload['date_start'], $payload['date_end']);
    }

    public function testStrategyExecutionIntentInputUsesTopLevelExecutionBridge(): void
    {
        $service = new SimulationExecutionReadinessService();

        $input = $service->buildStrategyExecutionIntentInput([
            'id' => 91,
            'project_name' => 'Bridge Strategy',
            'execution_intent_id' => 321,
            'input' => [
                'project_name' => 'Bridge Strategy',
                'source_evidence' => ['site_visit' => 'checked'],
                'review_status' => 'approved',
            ],
            'scores' => ['total_score' => 82, 'items' => []],
            'recommendation' => [
                'decision' => 'go',
                'decision_direction' => 'review competitors and lease',
            ],
            'risk' => ['risk_level' => 'medium'],
            'data_snapshot' => ['local_data_used' => true, 'source_summary' => ['daily_reports']],
        ], [
            'hotel_id' => 7,
            'date_start' => '2026-06-25',
        ]);

        self::assertSame('execution_ready', $input['evidence']['readiness_stage']);
        self::assertSame([], $input['evidence']['readiness_missing_evidence']);
    }

    public function testQuantExecutionIntentInputUsesTopLevelExecutionBridge(): void
    {
        $service = new SimulationExecutionReadinessService();
        $quantInput = array_merge($this->quantInput(), [
            'source_evidence' => ['daily_report' => 'checked'],
            'review_status' => 'approved',
        ]);

        $input = $service->buildQuantExecutionIntentInput([
            'id' => 92,
            'project_name' => 'Bridge Quant',
            'execution_intent_id' => 322,
            'input' => $quantInput,
            'result' => $this->quantResult(),
            'scenarios' => $this->quantScenarios(),
            'risk_hints' => [],
        ], [
            'hotel_id' => 7,
            'date_start' => '2026-06-25',
        ]);

        self::assertSame('execution_ready', $input['evidence']['readiness_stage']);
        self::assertSame([], $input['evidence']['readiness_missing_evidence']);
    }

    private function quantInput(): array
    {
        return [
            'roomCount' => 80,
            'adr' => 320,
            'occupancyRate' => 78,
            'monthlyRent' => 120000,
            'laborCost' => 45000,
            'utilityCost' => 12000,
            'otaCommissionRate' => 12,
            'consumableCost' => 8000,
            'maintenanceCost' => 6000,
            'otherFixedCost' => 5000,
            'decorationInvestment' => 3200000,
            'furnitureInvestment' => 800000,
            'openingCost' => 300000,
            'otherInvestment' => 200000,
        ];
    }

    private function quantResult(): array
    {
        return [
            'monthlyRevenue' => 620000,
            'monthlyNetCashflow' => 210000,
            'paybackMonths' => 22.1,
            'rentRatio' => 0.19,
            'riskLevel' => '中风险',
        ];
    }

    private function quantScenarios(): array
    {
        return [
            ['scenarioType' => '保守情景', 'monthlyNetCashflow' => 120000],
            ['scenarioType' => '基准情景', 'monthlyNetCashflow' => 210000],
            ['scenarioType' => '乐观情景', 'monthlyNetCashflow' => 280000],
        ];
    }
}
