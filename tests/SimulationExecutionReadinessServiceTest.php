<?php
declare(strict_types=1);

namespace Tests;

use app\service\SimulationExecutionReadinessService;
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
