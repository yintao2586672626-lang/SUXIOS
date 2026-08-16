<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationInterventionJudgmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationInterventionJudgmentServiceTest extends TestCase
{
    public function testProspectiveVerifiedAbsoluteThresholdIsSupportedWithoutCausalClaim(): void
    {
        $result = $this->judge();

        self::assertSame('supported', $result['verdict']);
        self::assertSame(['target_threshold_met'], $result['reason_codes']);
        self::assertSame('verified', $result['comparison']['status']);
        self::assertSame(3.0, $result['comparison']['target_assessment']['observed_progress']);
        self::assertTrue($result['comparison']['target_assessment']['threshold_met']);
        self::assertSame('within_bounds', $result['guard_results'][0]['status']);
        self::assertFalse($result['causality_claimed']);
        self::assertSame(
            ['verdict', 'reason_codes', 'comparison', 'guard_results', 'result_summary', 'causality_claimed'],
            array_keys($result)
        );
    }

    public function testPercentThresholdUsesFrozenBaselineAndSupportsDecreaseDirection(): void
    {
        $baseline = $this->snapshot([
            'metric_key' => 'cancellation_rate',
            'unit' => 'percent',
            'value' => 20,
        ]);
        $followup = $this->followup([
            'metric_key' => 'cancellation_rate',
            'unit' => 'percent',
            'value' => 17,
        ]);
        $result = $this->judge(
            intervention: [
                'target_metric_key' => 'cancellation_rate',
                'expected_direction' => 'decrease',
                'expected_delta' => 10,
                'expected_delta_unit' => 'percent',
                'baseline_snapshot' => $baseline,
            ],
            input: ['followup_snapshot' => $followup]
        );

        self::assertSame('supported', $result['verdict']);
        self::assertSame(15.0, $result['comparison']['target_assessment']['observed_progress']);
        self::assertFalse($result['causality_claimed']);
    }

    #[DataProvider('contradictionProvider')]
    public function testCompleteEvidenceCanBeContradicted(
        array $intervention,
        array $input,
        string $expectedReason
    ): void {
        $result = $this->judge(intervention: $intervention, input: $input);

        self::assertSame('contradicted', $result['verdict']);
        self::assertContains($expectedReason, $result['reason_codes']);
        self::assertFalse($result['causality_claimed']);
    }

    /** @return iterable<string, array{0:array<string,mixed>,1:array<string,mixed>,2:string}> */
    public static function contradictionProvider(): iterable
    {
        yield 'stop condition' => [
            [],
            [
                'stop_triggered' => true,
                'stop_evidence_refs' => ['operation_alert#9'],
            ],
            'stop_condition_triggered',
        ];
        yield 'guard breached' => [
            [],
            [
                'guard_observations' => [[
                    'metric_key' => 'refund_rate',
                    'value' => 8,
                    'quality_status' => 'verified',
                    'readback_status' => 'readback_verified',
                    'period_start' => '2026-08-04',
                    'period_end' => '2026-08-10',
                    'sample_size' => 7,
                    'evidence_refs' => ['operation_metric#guard-2'],
                ]],
            ],
            'guard_metric_breached:refund_rate',
        ];
        yield 'target reverses' => [
            [],
            [
                'followup_snapshot' => self::staticFollowup(['value' => 8]),
            ],
            'target_metric_reversed',
        ];
    }

    #[DataProvider('indeterminateGuardProvider')]
    public function testHigherPriorityEvidenceAndInterferenceGapsStayIndeterminate(
        array $intervention,
        array $task,
        array $evidenceRows,
        array $input,
        string $expectedReason
    ): void {
        $result = $this->judge(
            intervention: $intervention,
            task: $task,
            evidenceRows: $evidenceRows,
            input: $input
        );

        self::assertSame('indeterminate', $result['verdict']);
        self::assertContains($expectedReason, $result['reason_codes']);
        self::assertFalse($result['causality_claimed']);
    }

    /** @return iterable<string, array{0:array<string,mixed>,1:array<string,mixed>,2:array<int,mixed>,3:array<string,mixed>,4:string}> */
    public static function indeterminateGuardProvider(): iterable
    {
        yield 'retrospective contract cannot be rescued by a target lift' => [
            ['design_timing' => 'retrospective'],
            [],
            self::validEvidenceRows(),
            [],
            'intervention_contract_retrospective',
        ];
        yield 'task must be executed' => [
            [],
            ['status' => 'pending_execute'],
            self::validEvidenceRows(),
            [],
            'execution_task_not_executed',
        ];
        yield 'execution evidence required' => [
            [],
            [],
            [],
            [],
            'execution_evidence_missing',
        ];
        yield 'observation window must end' => [
            [],
            [],
            self::validEvidenceRows(),
            ['assessed_at' => '2026-08-09 12:00:00'],
            'observation_window_not_ended',
        ];
        yield 'external interference outranks apparent lift' => [
            [],
            [],
            self::validEvidenceRows(),
            ['external_interferences' => [['code' => 'holiday_demand_spike', 'status' => 'present']]],
            'external_interference_present',
        ];
        yield 'unverified target readback stays unknown' => [
            [],
            [],
            self::validEvidenceRows(),
            ['followup_snapshot' => self::staticFollowup(['readback_status' => 'unverified'])],
            'followup_readback_unverified',
        ];
        yield 'insufficient followup sample stays unknown' => [
            [],
            [],
            self::validEvidenceRows(),
            ['followup_snapshot' => self::staticFollowup(['sample_size' => 3])],
            'followup_sample_size_insufficient',
        ];
        yield 'risk observation must exist' => [
            [],
            [],
            self::validEvidenceRows(),
            ['guard_observations' => []],
            'guard_observation_missing:refund_rate',
        ];
    }

    #[DataProvider('inconclusiveMovementProvider')]
    public function testAlignedButBelowThresholdOrUnchangedIsIndeterminate(
        float $followupValue,
        string $expectedReason
    ): void {
        $result = $this->judge(input: [
            'followup_snapshot' => $this->followup(['value' => $followupValue]),
        ]);

        self::assertSame('indeterminate', $result['verdict']);
        self::assertSame([$expectedReason], $result['reason_codes']);
    }

    /** @return iterable<string, array{0:float,1:string}> */
    public static function inconclusiveMovementProvider(): iterable
    {
        yield 'same direction below threshold' => [11.0, 'target_threshold_not_met'];
        yield 'unchanged' => [10.0, 'target_metric_unchanged'];
    }

    public function testUnknownRiskMetricAndUnverifiedRiskObservationRemainIndeterminate(): void
    {
        $result = $this->judge(
            intervention: ['risk_metric_keys' => ['refund_rate', 'unknown_guard']],
            input: [
                'guard_observations' => [[
                    ...$this->guardObservation(),
                    'quality_status' => 'partial',
                ]],
            ]
        );

        self::assertSame('indeterminate', $result['verdict']);
        self::assertContains('guard_observation_quality_unverified:refund_rate', $result['reason_codes']);
        self::assertContains('risk_metric_not_in_goal_guard_metrics:unknown_guard', $result['reason_codes']);
        self::assertCount(2, $result['guard_results']);
    }

    public function testAutomatedMonitorPreflightFailureCannotBePromotedToSupported(): void
    {
        $result = $this->judge(input: [
            'monitor_preflight_reason_codes' => [
                'execution_evidence_source_unverified',
                'execution_task_ambiguous',
            ],
        ]);

        self::assertSame('indeterminate', $result['verdict']);
        self::assertContains('execution_evidence_source_unverified', $result['reason_codes']);
        self::assertContains('execution_task_ambiguous', $result['reason_codes']);
        self::assertFalse($result['causality_claimed']);
    }

    /**
     * @param array<string, mixed> $goal
     * @param array<string, mixed> $intervention
     * @param array<string, mixed> $task
     * @param null|array<int, mixed> $evidenceRows
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function judge(
        array $goal = [],
        array $intervention = [],
        array $task = [],
        ?array $evidenceRows = null,
        array $input = []
    ): array {
        return (new OperationInterventionJudgmentService())->judge(
            [
                'id' => 21,
                'tenant_id' => 3,
                'hotel_id' => 80,
                'guard_metrics' => [[
                    'metric_key' => 'refund_rate',
                    'lower_bound' => 0,
                    'upper_bound' => 5,
                ]],
                ...$goal,
            ],
            [
                'id' => 31,
                'tenant_id' => 3,
                'hotel_id' => 80,
                'intent_id' => 41,
                'goal_contract_id' => 21,
                'design_timing' => 'prospective',
                'action_type' => 'price_review',
                'target_metric_key' => 'orders',
                'expected_direction' => 'increase',
                'expected_delta' => 2,
                'expected_delta_unit' => 'absolute',
                'risk_metric_keys' => ['refund_rate'],
                'baseline_snapshot' => $this->snapshot(),
                'observation_window_start' => '2026-08-04',
                'observation_window_end' => '2026-08-10',
                'comparison_mode' => 'same_length_period',
                'minimum_sample_size' => 7,
                ...$intervention,
            ],
            [
                'id' => 51,
                'tenant_id' => 3,
                'hotel_id' => 80,
                'intent_id' => 41,
                'status' => 'executed',
                'executed_at' => '2026-08-03 12:00:00',
                ...$task,
            ],
            $evidenceRows ?? [[
                'id' => 61,
                'task_id' => 51,
                'evidence_type' => 'manual_operation_execution',
                'created_by' => 9,
            ]],
            [
                'followup_snapshot' => $this->followup(),
                'guard_observations' => [$this->guardObservation()],
                'external_interferences' => [],
                'stop_triggered' => false,
                'assessed_at' => '2026-08-11 09:00:00',
                ...$input,
            ]
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function snapshot(array $overrides = []): array
    {
        return [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'platform_hotel_id' => 'ctrip-80',
            'business_module' => 'operations',
            'subject' => 'hotel',
            'metric_key' => 'orders',
            'unit' => 'count',
            'source_method' => 'profile_capture',
            'date_role' => 'business_date',
            'fact_scope' => 'ota_channel',
            'period_start' => '2026-07-27',
            'period_end' => '2026-08-02',
            'captured_at' => '2026-08-03 08:00:00',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'value' => 10,
            'sample_size' => 7,
            'evidence_refs' => ['online_daily_data#baseline'],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function followup(array $overrides = []): array
    {
        return self::staticFollowup($overrides);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private static function staticFollowup(array $overrides = []): array
    {
        return [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'platform_hotel_id' => 'ctrip-80',
            'business_module' => 'operations',
            'subject' => 'hotel',
            'metric_key' => 'orders',
            'unit' => 'count',
            'source_method' => 'profile_capture',
            'date_role' => 'business_date',
            'fact_scope' => 'ota_channel',
            'period_start' => '2026-08-04',
            'period_end' => '2026-08-10',
            'captured_at' => '2026-08-11 08:00:00',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'value' => 13,
            'sample_size' => 7,
            'evidence_refs' => ['online_daily_data#followup'],
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function guardObservation(): array
    {
        return [
            'metric_key' => 'refund_rate',
            'value' => 4,
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'period_start' => '2026-08-04',
            'period_end' => '2026-08-10',
            'sample_size' => 7,
            'evidence_refs' => ['operation_metric#guard-1'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function validEvidenceRows(): array
    {
        return [[
            'id' => 61,
            'task_id' => 51,
            'evidence_type' => 'manual_operation_execution',
            'created_by' => 9,
        ]];
    }
}
