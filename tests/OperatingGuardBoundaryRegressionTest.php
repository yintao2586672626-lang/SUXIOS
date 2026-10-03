<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingGoalInterventionMonitorService;
use app\service\OperationInterventionJudgmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperatingGuardBoundaryRegressionTest extends TestCase
{
    #[DataProvider('foreignGuardProvider')]
    public function testForeignGuardObservationCannotSupportAnIntervention(array $overrides, string $reason): void
    {
        $result = $this->judge(guard: $overrides);
        self::assertSame('indeterminate', $result['verdict']);
        self::assertContains($reason . ':refund_rate', $result['reason_codes']);
        self::assertSame('indeterminate', $result['guard_results'][0]['status']);
        self::assertFalse($result['causality_claimed']);
    }

    public static function foreignGuardProvider(): iterable
    {
        yield 'tenant' => [['tenant_id' => 999], 'guard_observation_tenant_mismatch'];
        yield 'hotel' => [['hotel_id' => 81], 'guard_observation_hotel_mismatch'];
        yield 'hotel alias' => [['system_hotel_id' => 81], 'guard_observation_hotel_mismatch'];
        yield 'platform' => [['platform' => 'meituan'], 'guard_observation_platform_mismatch'];
        yield 'provider hotel' => [['platform_hotel_id' => 'ctrip-81'], 'guard_observation_platform_hotel_mismatch'];
        yield 'fact scope' => [['fact_scope' => 'whole_hotel_accommodation'], 'guard_observation_fact_scope_mismatch'];
        yield 'nested tenant conflict' => [['scope' => ['tenant_id' => 999]], 'guard_observation_tenant_mismatch'];
        yield 'tenant not proven by reference' => [['tenant_id' => null], 'guard_observation_tenant_unverified'];
        yield 'hotel not proven by reference' => [['hotel_id' => null, 'system_hotel_id' => null], 'guard_observation_hotel_unverified'];
        yield 'channel missing' => [['platform' => null], 'guard_observation_platform_unverified'];
        yield 'scope missing' => [['fact_scope' => null], 'guard_observation_fact_scope_unverified'];
    }

    public function testExplicitWholeHotelGuardCanProtectAnOtaGoal(): void
    {
        $result = $this->judge(
            definition: ['lower_bound' => 0, 'upper_bound' => 5, 'fact_scope' => 'whole_hotel_accommodation', 'platform' => 'hotel'],
            guard: ['fact_scope' => 'whole_hotel_accommodation', 'platform' => 'hotel', 'platform_hotel_id' => 'system-80']
        );
        self::assertSame('supported', $result['verdict']);
        self::assertSame('within_bounds', $result['guard_results'][0]['status']);
    }

    public function testWholeHotelGuardDoesNotRequireAnOtaPlatform(): void
    {
        $result = $this->judge(
            definition: ['lower_bound' => 0, 'upper_bound' => 5, 'fact_scope' => 'whole_hotel_accommodation', 'platform' => null],
            guard: ['fact_scope' => 'whole_hotel_accommodation', 'platform' => null, 'platform_hotel_id' => null]
        );
        self::assertSame('supported', $result['verdict']);
        self::assertSame('within_bounds', $result['guard_results'][0]['status']);
        self::assertSame([], $result['guard_results'][0]['reason_codes']);
    }

    public function testWholeHotelGuardStillRejectsAnExplicitOtaChannelConflict(): void
    {
        $result = $this->judge(
            definition: ['lower_bound' => 0, 'upper_bound' => 5, 'fact_scope' => 'whole_hotel_accommodation', 'platform' => null],
            guard: ['fact_scope' => 'whole_hotel_accommodation', 'platform' => 'meituan']
        );
        self::assertSame('indeterminate', $result['verdict']);
        self::assertContains('guard_observation_platform_mismatch:refund_rate', $result['reason_codes']);
    }

    public function testConflictingWholeHotelDefinitionCannotBecomeAnOtaGuard(): void
    {
        $result = $this->judge(
            definition: ['lower_bound' => 0, 'upper_bound' => 5, 'fact_scope' => 'whole_hotel_accommodation', 'platform' => 'ctrip'],
            guard: ['fact_scope' => 'whole_hotel_accommodation', 'platform' => null]
        );
        self::assertSame('indeterminate', $result['verdict']);
        self::assertContains('guard_definition_platform_scope_mismatch:refund_rate', $result['reason_codes']);
    }

    #[DataProvider('conditionProvider')]
    public function testMonitorAndAssessmentUseTheSameGuardCondition(array $condition, float $value, string $expected): void
    {
        $monitor = $this->monitor($condition, $value);
        self::assertSame($expected, $monitor['guard_results'][0]['status']);
        $assessment = $this->judge(definition: $condition, guard: ['value' => $value]);
        self::assertSame($expected, $assessment['guard_results'][0]['status']);
        self::assertSame($expected === 'breached' ? 'contradicted' : 'supported', $assessment['verdict']);
    }

    public static function conditionProvider(): iterable
    {
        yield 'legacy threshold below' => [['threshold' => 5], 4.0, 'within_bounds'];
        yield 'legacy threshold equality' => [['threshold' => 5], 5.0, 'within_bounds'];
        yield 'legacy threshold exceeded' => [['threshold' => 5], 6.0, 'breached'];
        yield 'legacy zero threshold' => [['threshold' => 0], 0.0, 'within_bounds'];
        yield 'upper end of range breached' => [['lower_bound' => 0, 'upper_bound' => 5], 6.0, 'breached'];
        yield 'lower end of range breached' => [['bounds' => ['minimum' => 2, 'maximum' => 5]], 1.0, 'breached'];
        yield 'range zero is real' => [['lower_bound' => 0, 'upper_bound' => 5], 0.0, 'within_bounds'];
        yield 'strict below satisfied' => [['operator' => '<', 'threshold' => 5], 4.0, 'within_bounds'];
        yield 'strict below equality breached' => [['operator' => '<', 'threshold' => 5], 5.0, 'breached'];
        yield 'strict above satisfied' => [['operator' => 'above', 'threshold' => 3], 4.0, 'within_bounds'];
        yield 'strict above equality breached' => [['operator' => 'gt', 'threshold' => 4], 4.0, 'breached'];
        yield 'equal satisfied' => [['operator' => 'equals', 'threshold' => 4], 4.0, 'within_bounds'];
        yield 'equal breached' => [['operator' => '=', 'threshold' => 4], 5.0, 'breached'];
        yield 'inclusive lower equality' => [['operator' => 'gte', 'threshold' => 4], 4.0, 'within_bounds'];
        yield 'inclusive upper equality' => [['operator' => 'lte', 'threshold' => 4], 4.0, 'within_bounds'];
    }

    public function testInvertedGuardBoundsRemainUnverifiable(): void
    {
        $condition = ['lower_bound' => 5, 'upper_bound' => 2];
        self::assertSame('unavailable', $this->monitor($condition, 4)['guard_results'][0]['status']);
        $result = $this->judge(definition: $condition);
        self::assertSame('indeterminate', $result['verdict']);
        self::assertSame('indeterminate', $result['guard_results'][0]['status']);
    }

    public function testPersistedScalarMapKeepsThresholdOnlySemantics(): void
    {
        self::assertSame('within_bounds', $this->monitor(['threshold' => 5], 5, true)['guard_results'][0]['status']);
        self::assertSame('breached', $this->monitor(['threshold' => 5], 6, true)['guard_results'][0]['status']);
    }

    public function testLegacyThresholdStopConditionStillUsesAnInclusiveTrigger(): void
    {
        foreach ([4.0 => 'triggered', 5.0 => 'triggered', 6.0 => 'clear'] as $value => $expected) {
            $result = OperatingGoalInterventionMonitorService::evaluateGuardValue(['threshold' => 5], (float)$value, true);
            self::assertSame($expected, $result['status']);
            self::assertSame('<=', $result['operator']);
        }
        self::assertSame('unavailable', OperatingGoalInterventionMonitorService::evaluateGuardValue(['threshold' => INF], 5)['status']);
    }

    private function monitor(array $condition, float $value, bool $scalarMap = false): array
    {
        $goal = [
            'id' => 21, 'version_no' => 1, 'tenant_id' => 3, 'hotel_id' => 80,
            'primary_metric_key' => 'orders', 'effective_from' => '2026-08-01', 'effective_to' => '2026-08-31',
            'guard_metrics' => $scalarMap ? ['refund_rate' => $condition['threshold']] : [['metric_key' => 'refund_rate', ...$condition]], 'stop_conditions' => [],
        ];
        $goalService = new class($goal) {
            public function __construct(private array $goal) {}
            public function overview(int $tenant, array $hotels, int $hotel): array
            { return ['current_goal_contract' => $this->goal, 'goal_contract_history' => [$this->goal], 'interventions' => []]; }
        };
        $snapshotService = new class($value) {
            public function __construct(private float $guardValue) {}
            public function snapshot(int $tenant, int $hotel, string $metric, string $from, string $to, array $context): array
            { return ['status' => 'ready', 'snapshot' => ['value' => $metric === 'orders' ? 13 : $this->guardValue,
                'quality_status' => 'verified', 'readback_status' => 'readback_verified',
                'evidence_refs' => ['fixture#' . $metric], 'period_start' => $from, 'period_end' => $to]]; }
        };
        return (new OperatingGoalInterventionMonitorService($goalService, $snapshotService))
            ->monitor(3, 80, '2026-08-11', false);
    }

    private function judge(array $definition = [], array $guard = []): array
    {
        $snapshot = [
            'system_hotel_id' => 80, 'tenant_id' => 3, 'hotel_id' => 80,
            'platform' => 'ctrip', 'platform_hotel_id' => 'ctrip-80', 'business_module' => 'operations',
            'subject' => 'hotel', 'metric_key' => 'orders', 'unit' => 'count', 'source_method' => 'profile_capture',
            'date_role' => 'business_date', 'fact_scope' => 'ota_channel',
            'period_start' => '2026-07-27', 'period_end' => '2026-08-02', 'captured_at' => '2026-08-03 08:00:00',
            'quality_status' => 'verified', 'readback_status' => 'readback_verified', 'value' => 10,
            'sample_size' => 7, 'evidence_refs' => ['online_daily_data#baseline'],
        ];
        $followup = [...$snapshot, 'period_start' => '2026-08-04', 'period_end' => '2026-08-10',
            'captured_at' => '2026-08-11 08:00:00', 'value' => 13, 'evidence_refs' => ['online_daily_data#followup']];
        return (new OperationInterventionJudgmentService())->judge(
            ['id' => 21, 'tenant_id' => 3, 'hotel_id' => 80, 'guard_metrics' => [[
                'metric_key' => 'refund_rate', ...($definition === [] ? ['lower_bound' => 0, 'upper_bound' => 5] : $definition)]]],
            ['id' => 31, 'tenant_id' => 3, 'hotel_id' => 80, 'intent_id' => 41, 'goal_contract_id' => 21,
                'design_timing' => 'prospective', 'action_type' => 'price_review', 'target_metric_key' => 'orders',
                'expected_direction' => 'increase', 'expected_delta' => 2, 'expected_delta_unit' => 'absolute',
                'risk_metric_keys' => ['refund_rate'], 'baseline_snapshot' => $snapshot,
                'observation_window_start' => '2026-08-04', 'observation_window_end' => '2026-08-10',
                'comparison_mode' => 'same_length_period', 'minimum_sample_size' => 7],
            ['id' => 51, 'tenant_id' => 3, 'hotel_id' => 80, 'intent_id' => 41,
                'status' => 'executed', 'executed_at' => '2026-08-03 12:00:00'],
            [['id' => 61, 'task_id' => 51, 'evidence_type' => 'manual_operation_execution', 'created_by' => 9]],
            ['followup_snapshot' => $followup, 'guard_observations' => [[...$followup,
                'metric_key' => 'refund_rate', 'unit' => 'percent', 'value' => 4,
                'evidence_refs' => ['fixture#guard'], ...$guard]],
                'external_interferences' => [], 'stop_triggered' => false, 'assessed_at' => '2026-08-11 09:00:00']
        );
    }
}
